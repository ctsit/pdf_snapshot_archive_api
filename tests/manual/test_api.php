<?php
namespace UF_CTSI\PdfSnapshotArchiveApi\Tests;

/**
 * Manual test of the PDF Snapshot Archive API against a live REDCap project.
 *
 * Usage: php tests/manual/test_api.php
 *
 * Reads TEST_PROJECT_PID from <config dir>/.env, then reads the matching row
 * of <config dir>/credentials.csv (REDCapR::retrieve_credential_local() format).
 * Responses are saved to <config dir>/output/.
 *
 * <config dir> is $REDCAP_EM_TEST_DIR if set, else tests/manual/. Set
 * REDCAP_EM_TEST_DIR to a directory outside the module whenever the module
 * directory is web-served, or the web server will serve these files.
 */

// This file lives inside a web-served module directory; never run it from a web request
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

class ApiTest {
	const PREFIX = 'pdf_snapshot_archive_api';

	private string $dir;
	private array $credential = [];
	private int $failures = 0;

	function __construct(string $dir) {
		$this->dir = $dir;
	}

	/**
	 * Runs every check. Returns the process exit status: 0 if all checks pass,
	 * 1 if any fail, 2 if the test configuration is missing or invalid.
	 */
	public function run(): int {
		$env = $this->loadEnv("{$this->dir}/.env");
		$pid = $env['TEST_PROJECT_PID'] ?? getenv('TEST_PROJECT_PID') ?: '';
		if ($pid === '') {
			fwrite(STDERR, "TEST_PROJECT_PID is not set in {$this->dir}/.env\n");
			return 2;
		}
		try {
			$this->credential = $this->loadCredential("{$this->dir}/credentials.csv", $pid);
		} catch (\RuntimeException $e) {
			fwrite(STDERR, $e->getMessage() . "\n");
			return 2;
		}
		echo "Testing project $pid at {$this->credential['redcap_uri']} as {$this->credential['username']}\n\n";

		// Built-in framework actions
		$r = $this->call(['action' => '__version', 'returnFormat' => 'json']);
		$this->check("__version", $r['status'] === 200, $this->snippet($r['body']));

		$r = $this->call(['action' => '__actions', 'returnFormat' => 'json']);
		$actions = array_keys(json_decode($r['body'], true)['auth-actions'] ?? []);
		sort($actions);
		$this->check("__actions lists exactly get-file and get-items",
			$r['status'] === 200 && $actions === ['get-file', 'get-items'], $this->snippet($r['body']));

		// get-items with no filters returns every item, with full details
		$r = $this->call(['action' => 'get-items', 'returnFormat' => 'json']);
		$items = json_decode($r['body'], true);
		$this->check("get-items json", $r['status'] === 200 && is_array($items), count($items ?? []) . " items");
		if (!empty($items)) {
			$this->check("get-items rows have full details",
				count(array_diff(['item_id', 'doc_name', 'record', 'form_name', 'is_econsent'], array_keys($items[0]))) === 0,
				implode(',', array_keys($items[0])));
		}

		$r = $this->call(['action' => 'get-items', 'returnFormat' => 'csv']);
		$ok = $r['status'] === 200 && strpos($r['body'], 'item_id') !== false;
		$this->check("get-items csv", $ok, $ok ? "saved to " . $this->saveOutput('get-items.csv', $r['body']) : $this->snippet($r['body']));

		$r = $this->call(['action' => 'get-items', 'returnFormat' => 'xml']);
		$xml = $r['status'] === 200 ? simplexml_load_string($r['body']) : false;
		$this->check("get-items xml", $xml !== false && count($xml->item) === count($items ?? []), $this->snippet($r['body']));

		if (empty($items)) {
			echo "\nThe PDF Snapshot Archive is empty; skipping filter and file tests.\n";
		} else {
			$first = $items[0];
			$ids_of = fn($rows) => array_map(fn($i) => $i['item_id'], $rows ?? []);

			// Filters
			$r = $this->call(['action' => 'get-items', 'item_ids' => [$first['item_id']], 'returnFormat' => 'json']);
			$got = json_decode($r['body'], true);
			$this->check("get-items item_ids as an array", $r['status'] === 200 && $ids_of($got) === [$first['item_id']],
				"item_ids[]={$first['item_id']} gave " . json_encode($ids_of($got)));

			$wanted = array_slice($ids_of($items), 0, 2);
			$r = $this->call(['action' => 'get-items', 'item_ids' => implode(',', $wanted), 'returnFormat' => 'json']);
			$got = $ids_of(json_decode($r['body'], true));
			sort($got);
			sort($wanted);
			$this->check("get-items item_ids as a comma-separated list", $r['status'] === 200 && $got === $wanted,
				"item_ids=" . implode(',', $wanted) . " gave " . json_encode($got));

			$r = $this->call(['action' => 'get-items', 'records' => $first['record'], 'returnFormat' => 'json']);
			$got = json_decode($r['body'], true);
			$expected = count(array_filter($items, fn($i) => $i['record'] === $first['record']));
			$this->check("get-items records filter",
				$r['status'] === 200 && count($got ?? []) === $expected
				&& count(array_filter($got, fn($i) => $i['record'] !== $first['record'])) === 0,
				count($got ?? []) . " items for record {$first['record']}");

			// get-file
			$r = $this->call(['action' => 'get-file', 'item_id' => $first['item_id']]);
			$is_pdf = substr($r['body'], 0, 4) === '%PDF';
			$ok = $r['status'] === 200 && $is_pdf;
			$this->check("get-file returns a PDF", $ok,
				$ok ? strlen($r['body']) . " bytes saved to " . $this->saveOutput(basename($first['doc_name']), $r['body']) : $this->snippet($r['body']));
		}

		// Error handling
		$r = $this->call(['action' => 'get-items', 'item_ids' => 'abc', 'returnFormat' => 'json']);
		$this->check("get-items with non-integer item_ids returns 400", $r['status'] === 400, "status {$r['status']}");

		$r = $this->call(['action' => 'get-items', 'item_ids' => '2147483000', 'returnFormat' => 'json']);
		$this->check("get-items with unknown item_ids returns an empty list",
			$r['status'] === 200 && json_decode($r['body'], true) === [], "status {$r['status']}, " . $this->snippet($r['body']));

		$r = $this->call(['action' => 'get-file', 'returnFormat' => 'json']);
		$this->check("get-file without item_id returns 400", $r['status'] === 400, "status {$r['status']}");

		$r = $this->call(['action' => 'get-file', 'item_id' => 'abc', 'returnFormat' => 'json']);
		$this->check("get-file with non-integer item_id returns 400", $r['status'] === 400, "status {$r['status']}");

		$r = $this->call(['action' => 'get-file', 'item_id' => '2147483000']);
		$this->check("get-file with unknown item_id returns 404", $r['status'] === 404, "status {$r['status']}");

		$r = $this->call(['action' => 'no-such-action', 'returnFormat' => 'json']);
		$this->check("unknown action is rejected", $r['status'] === 400, "status {$r['status']}");

		echo "\n" . ($this->failures === 0 ? "All checks passed." : "{$this->failures} check(s) failed.") . "\n";
		return $this->failures === 0 ? 0 : 1;
	}

	private function loadEnv(string $path): array {
		if (!is_file($path)) {
			fwrite(STDERR, "No .env file found at $path\n");
			return [];
		}
		$env = [];
		foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
			[$key, $value] = array_map('trim', explode('=', $line, 2));
			$env[$key] = trim($value, "\"'");
		}
		return $env;
	}

	private function loadCredential(string $path, string $project_id): array {
		if (!is_file($path)) {
			throw new \RuntimeException("Credentials file not found: $path");
		}
		$fh = fopen($path, 'r');
		$header = array_map('trim', fgetcsv($fh, null, ',', '"', '\\'));
		while (($values = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
			if (count($values) !== count($header)) continue;
			$row = array_combine($header, array_map('trim', $values));
			if ($row['project_id'] === $project_id) {
				fclose($fh);
				return $row;
			}
		}
		fclose($fh);
		throw new \RuntimeException("No row with project_id=$project_id in $path");
	}

	private function call(array $params): array {
		$params = array_merge([
			'token' => $this->credential['token'],
			'content' => 'externalModule',
			'prefix' => self::PREFIX,
		], $params);
		$ch = curl_init($this->credential['redcap_uri']);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query($params),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_TIMEOUT => 120,
		]);
		$body = curl_exec($ch);
		if ($body === false) {
			throw new \RuntimeException("curl error: " . curl_error($ch));
		}
		return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body];
	}

	private function check(string $label, bool $ok, string $detail = ''): void {
		if (!$ok) $this->failures++;
		echo ($ok ? "PASS" : "FAIL") . "  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
	}

	private function saveOutput(string $name, string $contents): string {
		$out_dir = "{$this->dir}/output";
		if (!is_dir($out_dir)) mkdir($out_dir, 0775, true);
		file_put_contents("$out_dir/$name", $contents);
		return "$out_dir/$name";
	}

	private function snippet(string $body): string {
		return substr(preg_replace('/\s+/', ' ', $body), 0, 200);
	}
}

exit((new ApiTest(rtrim(getenv('REDCAP_EM_TEST_DIR') ?: __DIR__, '/')))->run());
