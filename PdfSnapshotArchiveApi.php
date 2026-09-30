<?php
namespace UF_CTSI\PdfSnapshotArchiveApi;

use ExternalModules\AbstractExternalModule;

// API extension documentation
// https://github.com/vanderbilt-redcap/external-module-framework-docs/blob/main/api.md

/**
 * An error that maps directly to an HTTP status code in the API response.
 */
class ApiError extends \Exception {}

class PdfSnapshotArchiveApi extends AbstractExternalModule {

	// Attributes of an archive row that are never returned by get-item
	const HIDDEN_ATTRIBUTES = ['migration_status', 'migration_doc_id'];

	function redcap_module_api($action, $payload, $project_id, $user_id, $format, $returnFormat, $csvDelim) {
		try {
			if ($project_id === null) {
				throw new ApiError("This API requires a project-level API token.", 400);
			}

			$rights = $this->getTokenUserRights($project_id, $user_id);
			$Proj = new \Project($project_id);

			switch ($action) {
			case "list-items":
				return $this->formatResponse($this->listItems($Proj, $rights, $payload), $returnFormat, $csvDelim, "items", "item");
			case "get-item":
				return $this->formatResponse($this->getItem($Proj, $rights, $payload), $returnFormat, $csvDelim, "item");
			case "get-file":
				return $this->getFile($Proj, $rights, $payload);
			default:
				throw new ApiError("Action '$action' is not implemented.", 501);
			}
		} catch (ApiError $e) {
			return $this->framework->apiErrorResponse($e->getMessage(), $e->getCode());
		}
	}

	/**
	 * Returns the token user's rights on the project, mirroring the File Repository
	 * requirement that the user have the File Repository right to see the PDF Snapshot Archive.
	 * A non-super-user without the File Repository right gets a 403.
	 */
	function getTokenUserRights($project_id, $user_id): array {
		$user = $this->framework->getUser($user_id);
		$rights = $user->getRights($project_id);
		if (!is_array($rights)) {
			throw new ApiError("The token's user does not have access to this project.", 403);
		}
		$rights['is_super_user'] = $user->isSuperUser();
		if (!$rights['is_super_user'] && $rights['file_repository'] != '1') {
			throw new ApiError("You do not have File Repository privileges in this project.", 403);
		}
		return $rights;
	}

	/**
	 * Returns every archive row visible to the user, restricted to the user's DAG if any.
	 */
	function fetchItems(\Project $Proj, array $rights): array {
		return \PdfSnapshot::getPdfSnapshotArchiveFiles($Proj, $this->userGroupId($rights));
	}

	/**
	 * Returns one archive row visible to the user, or throws a 404.
	 */
	function fetchItem(\Project $Proj, array $rights, array $payload): array {
		$item_id = $this->requireItemId($payload);
		$item = \PdfSnapshot::getPdfSnapshotArchiveFiles($Proj, $this->userGroupId($rights), $item_id);
		if (empty($item)) {
			throw new ApiError("Item '$item_id' was not found in the PDF Snapshot Archive.", 404);
		}
		return $item;
	}

	function userGroupId(array $rights): ?int {
		return isinteger($rights['group_id'] ?? '') ? (int)$rights['group_id'] : null;
	}

	function requireItemId(array $payload): int {
		$item_id = $payload['item_id'] ?? '';
		if (!isinteger($item_id) || (int)$item_id <= 0) {
			throw new ApiError("The parameter 'item_id' is required and must be a positive integer.", 400);
		}
		return (int)$item_id;
	}

	function listItems(\Project $Proj, array $rights, array $payload): array {
		$record = $payload['record'] ?? '';
		$rows = [];
		foreach ($this->fetchItems($Proj, $rights) as $file) {
			if ($record !== '' && $file['record'] !== $record) continue;
			$rows[] = [
				'item_id' => (int)$file['doc_id'],
				'filename' => $file['doc_name'],
				'record' => $file['record'],
			];
		}
		return $rows;
	}

	function getItem(\Project $Proj, array $rights, array $payload): array {
		$file = $this->fetchItem($Proj, $rights, $payload);

		$item = ['item_id' => (int)$file['doc_id']];
		foreach ($file as $key => $value) {
			if (in_array($key, self::HIDDEN_ATTRIBUTES)) continue;
			$item[$key] = $value;
		}
		// Mirror the File Repository, which only reveals the IP when the system allows it
		if (!$GLOBALS['pdf_econsent_system_ip']) {
			unset($item['ip']);
		}

		// Attributes derived from project metadata, as shown in the File Repository
		$event_id = $file['event_id'];
		$item['form_name'] = $this->formName($Proj, $file);
		$item['survey_title'] = strip_tags($Proj->surveys[$file['survey_id']]['title'] ?? "");
		$item['event_name'] = $Proj->longitudinal ? ($Proj->getUniqueEventNames($event_id) ?: "") : "";
		$item['arm_num'] = $Proj->eventInfo[$event_id]['arm_num'] ?? "";
		$item['is_econsent'] = (\Econsent::econsentEnabledForSurvey($file['survey_id'])
			|| trim($file['identifier'] . $file['version'] . $file['type']) != '') ? 1 : 0;

		return $item;
	}

	/**
	 * Returns the PDF of one archive item. Like the File Repository, this requires
	 * full data export rights, including on the survey's instrument when there is one.
	 */
	function getFile(\Project $Proj, array $rights, array $payload): array {
		$file = $this->fetchItem($Proj, $rights, $payload);

		if (!$rights['is_super_user']) {
			$forms_export = \UserRights::convertFormRightsToArray($rights['data_export_instruments']);
			if (!in_array('1', array_map('strval', $forms_export), true)) {
				throw new ApiError("You do not have Full Data Set export privileges in this project.", 403);
			}
			$form_name = $this->formName($Proj, $file);
			if ($form_name !== "" && ($forms_export[$form_name] ?? '0') != '1') {
				throw new ApiError("You do not have Full Data Set export privileges for the instrument '$form_name'.", 403);
			}
		}

		// Timestamp prefix lets REDCap's temp file cleanup remove the copy
		$path = \Files::copyEdocToTemp($file['doc_id'], true, true);
		if ($path === false) {
			throw new ApiError("The file for item '{$file['doc_id']}' could not be retrieved.", 500);
		}

		\REDCap::logEvent(
			"Download PDF Snapshot File (API)",
			"docs_id = {$file['doc_id']},\nrecord = {$file['record']},\nsurvey_id = {$file['survey_id']},\nevent_id = {$file['event_id']},\ninstance = {$file['instance']}",
			null,
			$file['record'],
			$file['event_id'],
			$Proj->project_id
		);

		return $this->framework->apiFileResponse($path, $file['doc_name'], 'application/pdf');
	}

	function formName(\Project $Proj, array $file): string {
		return $Proj->surveys[$file['survey_id']]['form_name'] ?? "";
	}

	/**
	 * Formats a list of rows (when $item_tag is given) or a single row as json, csv, or xml.
	 */
	function formatResponse(array $data, $returnFormat, $csvDelim, string $root_tag, ?string $item_tag = null): array {
		$rows = $item_tag === null ? [$data] : $data;
		switch ($returnFormat) {
		case "json":
			return $this->framework->apiJsonResponse($data);
		case "csv":
			return $this->framework->apiCsvResponse($this->rowsToColumns($rows), $csvDelim);
		case "xml":
			return $this->framework->apiResponse($this->rowsToXml($rows, $root_tag, $item_tag));
		default:
			throw new ApiError("Return format '$returnFormat' is not supported.", 406);
		}
	}

	/**
	 * The framework's CSV writer wants [column => [values...]].
	 */
	function rowsToColumns(array $rows): array {
		$columns = [];
		foreach ($rows as $row) {
			foreach ($row as $key => $value) {
				$columns[$key][] = $value;
			}
		}
		// Keep a header row for an empty list-items response
		return empty($columns) ? ['item_id' => [], 'filename' => [], 'record' => []] : $columns;
	}

	function rowsToXml(array $rows, string $root_tag, ?string $item_tag): string {
		$element = function (array $row, string $tag): string {
			$xml = "<$tag>";
			foreach ($row as $key => $value) {
				$xml .= "<$key>" . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</$key>";
			}
			return $xml . "</$tag>";
		};
		$xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
		if ($item_tag === null) {
			return $xml . $element($rows[0], $root_tag);
		}
		$xml .= "<$root_tag>";
		foreach ($rows as $row) {
			$xml .= $element($row, $item_tag);
		}
		return $xml . "</$root_tag>";
	}
}
