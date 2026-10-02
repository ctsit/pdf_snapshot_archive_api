<?php
namespace UF_CTSI\PdfSnapshotArchiveApi;

use ExternalModules\AbstractExternalModule;

// API extension documentation
// https://github.com/vanderbilt-redcap/external-module-framework-docs/blob/main/api.md
//
// REDCap core has no public API for the PDF Snapshot Archive, so this module
// relies on core internals verified against REDCap 17.5.0:
// - \PdfSnapshot::getPdfSnapshotArchiveFiles() (Classes/PdfSnapshot.php) supplies
//   the archive rows, exactly as the File Repository page lists them.
// - \Econsent::econsentEnabledForSurvey() (Classes/Econsent.php) flags e-Consent PDFs.
// - The access rules mirror FileRepository::getFileList() for the 'pdf_archive'
//   folder and FileRepository::download() (Classes/FileRepository.php).
// If a REDCap upgrade renames these methods, changes the archive row columns, or
// changes the File Repository's rights checks, revisit this module against them.

/**
 * An error that maps directly to an HTTP status code in the API response.
 */
class ApiError extends \Exception {}

class PdfSnapshotArchiveApi extends AbstractExternalModule {

	// Attributes of an archive row that are never returned by get-items
	const HIDDEN_ATTRIBUTES = ['migration_status', 'migration_doc_id'];

	function redcap_module_api($action, $payload, $project_id, $user_id, $format, $returnFormat, $csvDelim) {
		try {
			if ($project_id === null) {
				throw new ApiError("This API requires a project-level API token.", 400);
			}

			$rights = $this->getTokenUserRights($project_id, $user_id);
			$Proj = new \Project($project_id);

			switch ($action) {
			case "get-items":
				return $this->formatResponse($this->getItems($Proj, $rights, $payload), $returnFormat, $csvDelim);
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
	 * REDCap::getUserRights() also parses form-level export rights into 'forms_export'.
	 */
	function getTokenUserRights($project_id, $user_id): array {
		$rights = current(\REDCap::getUserRights($user_id, $project_id));
		if (!is_array($rights)) {
			throw new ApiError("The token's user does not have access to this project.", 403);
		}
		$rights['is_super_user'] = $this->framework->getUser($user_id)->isSuperUser();
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

	/**
	 * Returns a payload parameter given either as an array (name[0]=a&name[1]=b)
	 * or as a comma-separated string, with blanks dropped. Returns null when the
	 * parameter is absent. With $integers, every value must be a positive integer.
	 */
	function parseList(array $payload, string $name, bool $integers = false): ?array {
		if (!isset($payload[$name])) return null;
		$values = is_array($payload[$name]) ? $payload[$name] : explode(',', (string)$payload[$name]);
		$values = array_values(array_filter(array_map(fn($v) => trim((string)$v), $values), fn($v) => $v !== ''));
		if ($integers) {
			foreach ($values as $value) {
				if (!isinteger($value) || (int)$value <= 0) {
					throw new ApiError("The parameter '$name' must contain only positive integers.", 400);
				}
			}
			$values = array_map('intval', $values);
		}
		return $values;
	}

	/**
	 * Returns full details of every visible item, optionally narrowed to the
	 * item_ids and/or records given. Like REDCap's Export Records, ids or records
	 * that match nothing are left out rather than raising an error.
	 */
	function getItems(\Project $Proj, array $rights, array $payload): array {
		$item_ids = $this->parseList($payload, 'item_ids', true);
		$records = $this->parseList($payload, 'records');

		$econsent_cache = [];
		$items = [];
		foreach ($this->fetchItems($Proj, $rights) as $file) {
			if ($item_ids !== null && !in_array((int)$file['doc_id'], $item_ids, true)) continue;
			if ($records !== null && !in_array((string)$file['record'], $records, true)) continue;
			$items[] = $this->itemDetails($Proj, $file, $econsent_cache);
		}
		return $items;
	}

	/**
	 * Every attribute of one archive row except the file itself, plus attributes
	 * derived from project metadata as shown in the File Repository.
	 * $econsent_cache holds econsentEnabledForSurvey() results by survey_id.
	 */
	function itemDetails(\Project $Proj, array $file, array &$econsent_cache): array {
		$item = ['item_id' => (int)$file['doc_id']];
		foreach ($file as $key => $value) {
			if (in_array($key, self::HIDDEN_ATTRIBUTES)) continue;
			$item[$key] = $value;
		}
		// Mirror the File Repository, which only reveals the IP when the system allows it
		if (!$GLOBALS['pdf_econsent_system_ip']) {
			unset($item['ip']);
		}

		$event_id = $file['event_id'];
		$survey_id = $file['survey_id'];
		$econsent_cache[$survey_id] ??= \Econsent::econsentEnabledForSurvey($survey_id);
		$item['form_name'] = $this->formName($Proj, $file);
		$item['survey_title'] = strip_tags($Proj->surveys[$survey_id]['title'] ?? "");
		$item['event_name'] = $Proj->longitudinal ? ($Proj->getUniqueEventNames($event_id) ?: "") : "";
		$item['arm_num'] = $Proj->eventInfo[$event_id]['arm_num'] ?? "";
		$item['is_econsent'] = ($econsent_cache[$survey_id]
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
			$forms_export = $rights['forms_export'] ?? [];
			if (!in_array('1', array_map('strval', $forms_export), true)) {
				throw new ApiError("You do not have Full Data Set export privileges in this project.", 403);
			}
			$form_name = $this->formName($Proj, $file);
			if ($form_name !== "" && ($forms_export[$form_name] ?? '0') != '1') {
				throw new ApiError("You do not have Full Data Set export privileges for the instrument '$form_name'.", 403);
			}
		}

		$contents = \REDCap::getFile($file['doc_id']);
		if (empty($contents)) {
			throw new ApiError("The file for item '{$file['doc_id']}' could not be retrieved.", 500);
		}
		// The framework deletes this temp file when the request ends
		$path = $this->framework->createTempFile();
		file_put_contents($path, $contents[2]);

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
	 * Formats a list of rows as json, csv, or xml (<items><item>...</item></items>).
	 */
	function formatResponse(array $rows, $returnFormat, $csvDelim): array {
		switch ($returnFormat) {
		case "json":
			return $this->framework->apiJsonResponse($rows);
		case "csv":
			return $this->framework->apiCsvResponse($this->rowsToColumns($rows), $csvDelim);
		case "xml":
			return $this->framework->apiResponse($this->rowsToXml($rows));
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
		// Keep a header row for an empty response
		return empty($columns) ? ['item_id' => []] : $columns;
	}

	function rowsToXml(array $rows): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n<items>";
		foreach ($rows as $row) {
			$xml .= "<item>";
			foreach ($row as $key => $value) {
				$xml .= "<$key>" . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</$key>";
			}
			$xml .= "</item>";
		}
		return $xml . "</items>";
	}
}
