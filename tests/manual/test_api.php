<?php
/**
 * Manual test of the PDF Snapshot Archive API against a live REDCap project.
 *
 * Usage: php tests/manual/test_api.php
 *
 * Reads TEST_PROJECT_PID from tests/manual/.env, then reads the matching row
 * of tests/manual/credentials.csv (REDCapR::retrieve_credential_local() format).
 */

const PREFIX = 'pdf_snapshot_archive_api';

$dir = __DIR__;

function load_env(string $path): array {
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

function load_credential(string $path, string $project_id): array {
	if (!is_file($path)) {
		throw new RuntimeException("Credentials file not found: $path");
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
	throw new RuntimeException("No row with project_id=$project_id in $path");
}

function api_call(array $credential, array $params): array {
	$params = array_merge([
		'token' => $credential['token'],
		'content' => 'externalModule',
		'prefix' => PREFIX,
	], $params);
	$ch = curl_init($credential['redcap_uri']);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($params),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_TIMEOUT => 120,
	]);
	$body = curl_exec($ch);
	if ($body === false) {
		throw new RuntimeException("curl error: " . curl_error($ch));
	}
	return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body];
}

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
	global $failures;
	if (!$ok) $failures++;
	echo ($ok ? "PASS" : "FAIL") . "  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

function save_output(string $name, string $contents): string {
	$out_dir = __DIR__ . '/output';
	if (!is_dir($out_dir)) mkdir($out_dir, 0775, true);
	file_put_contents("$out_dir/$name", $contents);
	return "output/$name";
}

function snippet(string $body): string {
	return substr(preg_replace('/\s+/', ' ', $body), 0, 200);
}

$env = load_env("$dir/.env");
$pid = $env['TEST_PROJECT_PID'] ?? getenv('TEST_PROJECT_PID') ?: '';
if ($pid === '') {
	fwrite(STDERR, "TEST_PROJECT_PID is not set in $dir/.env\n");
	exit(2);
}
try {
	$credential = load_credential("$dir/credentials.csv", $pid);
} catch (RuntimeException $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(2);
}
echo "Testing project $pid at {$credential['redcap_uri']} as {$credential['username']}\n\n";

// Built-in framework actions
$r = api_call($credential, ['action' => '__version', 'returnFormat' => 'json']);
check("__version", $r['status'] === 200, snippet($r['body']));

$r = api_call($credential, ['action' => '__actions', 'returnFormat' => 'json']);
$actions = json_decode($r['body'], true);
$body = json_encode($actions);
check("__actions lists list-items, get-item, get-file",
	$r['status'] === 200 && strpos($body, 'list-items') !== false
	&& strpos($body, 'get-item') !== false && strpos($body, 'get-file') !== false,
	snippet($r['body']));

// list-items
$r = api_call($credential, ['action' => 'list-items', 'returnFormat' => 'json']);
$items = json_decode($r['body'], true);
check("list-items json", $r['status'] === 200 && is_array($items), count($items ?? []) . " items");
if (!empty($items)) {
	check("list-items rows have item_id, filename, record",
		array_keys($items[0]) === ['item_id', 'filename', 'record']);
}

$r = api_call($credential, ['action' => 'list-items', 'returnFormat' => 'csv']);
$ok = $r['status'] === 200 && strpos($r['body'], 'item_id') !== false;
check("list-items csv", $ok, $ok ? "saved to " . save_output('list-items.csv', $r['body']) : snippet($r['body']));

$r = api_call($credential, ['action' => 'list-items', 'returnFormat' => 'xml']);
check("list-items xml", $r['status'] === 200 && simplexml_load_string($r['body']) !== false, snippet($r['body']));

if (empty($items)) {
	echo "\nThe PDF Snapshot Archive is empty; skipping item and file tests.\n";
} else {
	$first = $items[0];

	$r = api_call($credential, ['action' => 'list-items', 'record' => $first['record'], 'returnFormat' => 'json']);
	$filtered = json_decode($r['body'], true);
	check("list-items record filter",
		$r['status'] === 200 && !empty($filtered)
		&& count(array_filter($filtered, fn($i) => $i['record'] !== $first['record'])) === 0,
		count($filtered ?? []) . " items for record {$first['record']}");

	// get-item
	$r = api_call($credential, ['action' => 'get-item', 'item_id' => $first['item_id'], 'returnFormat' => 'json']);
	$item = json_decode($r['body'], true);
	check("get-item json",
		$r['status'] === 200 && ($item['item_id'] ?? null) == $first['item_id']
		&& ($item['doc_name'] ?? null) === $first['filename'] && ($item['record'] ?? null) === $first['record'],
		snippet($r['body']));

	$r = api_call($credential, ['action' => 'get-item', 'item_id' => $first['item_id'], 'returnFormat' => 'csv']);
	$ok = $r['status'] === 200 && strpos($r['body'], 'doc_name') !== false;
	check("get-item csv", $ok, $ok ? "saved to " . save_output("get-item_{$first['item_id']}.csv", $r['body']) : snippet($r['body']));

	// get-file
	$r = api_call($credential, ['action' => 'get-file', 'item_id' => $first['item_id']]);
	$is_pdf = substr($r['body'], 0, 4) === '%PDF';
	$ok = $r['status'] === 200 && $is_pdf;
	check("get-file returns a PDF", $ok,
		$ok ? strlen($r['body']) . " bytes saved to " . save_output(basename($first['filename']), $r['body']) : snippet($r['body']));
}

// Error handling
$r = api_call($credential, ['action' => 'get-item', 'returnFormat' => 'json']);
check("get-item without item_id returns 400", $r['status'] === 400, "status {$r['status']}");

$r = api_call($credential, ['action' => 'get-item', 'item_id' => 'abc', 'returnFormat' => 'json']);
check("get-item with non-integer item_id returns 400", $r['status'] === 400, "status {$r['status']}");

$r = api_call($credential, ['action' => 'get-item', 'item_id' => '2147483000', 'returnFormat' => 'json']);
check("get-item with unknown item_id returns 404", $r['status'] === 404, "status {$r['status']}");

$r = api_call($credential, ['action' => 'get-file', 'item_id' => '2147483000']);
check("get-file with unknown item_id returns 404", $r['status'] === 404, "status {$r['status']}");

$r = api_call($credential, ['action' => 'no-such-action', 'returnFormat' => 'json']);
check("unknown action is rejected", $r['status'] === 400, "status {$r['status']}");

echo "\n" . ($failures === 0 ? "All checks passed." : "$failures check(s) failed.") . "\n";
exit($failures === 0 ? 0 : 1);
