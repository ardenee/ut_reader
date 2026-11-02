#!/usr/bin/env php
<?php
/**
 * Source-level regression verifier for transfer blocking, scoped search,
 * file feedback length and dependency presentation.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$files = [
    'download_logs' => $root . '/download-logs.php',
    'blocklist' => $root . '/src/Infrastructure/Security/CatalogTransferBlocklist.php',
    'access_guard' => $root . '/src/Infrastructure/Security/CatalogPublicAccessGuard.php',
    'public_preflight' => $root . '/api/v1/public-upload-preflight.php',
    'public_upload' => $root . '/api/v1/public-upload.php',
    'bucket_chunk' => $root . '/api/v1/upload-bucket-chunk.php',
    'profile_batch' => $root . '/api/v1/profiled-upload-batch.php',
    'profile_chunk' => $root . '/api/v1/profiled-upload-chunk.php',
    'profile_legacy' => $root . '/profiled-upload.php',
    'pak_import' => $root . '/pak-import.php',
    'search_page' => $root . '/index.php',
    'search_repository' => $root . '/src/Infrastructure/Search/PdoCatalogSearchRepository.php',
    'search_writer' => $root . '/src/Infrastructure/Metadata/CompactSearchProjectionWriter.php',
    'lookup_writer' => $root . '/src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    'overflow_writer' => $root . '/src/Infrastructure/Metadata/CompactTermOverflowWriter.php',
    'name_backfill' => $root . '/bin/backfill-name-search-projection.php',
    'feedback' => $root . '/lib/CatalogFileFeedback.php',
    'file_info' => $root . '/file-info.php',
    'dependency_js' => $root . '/assets/file-dependency-display.js',
    'migration' => $root . '/migrations/202608280003_transfer_blocklist_feedback_search.php',
    'install' => $root . '/install.sql',
];

$source = [];
foreach ($files as $name => $path) {
    $value = @file_get_contents($path);
    if (!is_string($value)) {
        fwrite(STDERR, "[FAIL] Could not read {$path}\n");
        exit(1);
    }
    $source[$name] = $value;
}

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$check(
    'download_logs_bulk_admin',
    str_contains($source['download_logs'], 'delete_selected')
        && str_contains($source['download_logs'], 'block_selected_ips')
        && str_contains($source['download_logs'], 'unblock_selected_ips')
        && str_contains($source['download_logs'], 'Blocked transfer IPs')
        && str_contains($source['download_logs'], 'name="ids[]"'),
    'Download Logs must support selected-row deletion plus selected-IP block/unblock actions.'
);

$check(
    'transfer_blocklist_is_transfer_only',
    str_contains($source['blocklist'], 'ue_transfer_blocked_ips')
        && str_contains($source['access_guard'], 'transferAllowedOrThrow')
        && str_contains($source['access_guard'], 'Website browsing remains available.')
        && !str_contains($source['access_guard'], 'public function pageAllowedOrThrow'),
    'The persistent IP blocklist must be checked by transfer actions without becoming a general page-access ban.'
);

$uploadSources = [
    $source['public_preflight'],
    $source['public_upload'],
    $source['bucket_chunk'],
    $source['profile_batch'],
    $source['profile_chunk'],
    $source['profile_legacy'],
    $source['pak_import'],
];
$allUploadBlocked = true;
foreach ($uploadSources as $uploadSource) {
    if (!str_contains($uploadSource, 'transferAllowedOrThrow')) {
        $allUploadBlocked = false;
        break;
    }
}
$check(
    'upload_endpoints_enforce_blocklist',
    $allUploadBlocked
        && str_contains($source['public_upload'], "['chunk', 'complete']")
        && str_contains($source['public_upload'], "'cancel'"),
    'Browser/public/admin upload transfer paths must enforce the blocklist while cancellation stays available.'
);

$check(
    'search_scopes_and_file_types',
    str_contains($source['search_page'], "name=\"scope[]\"")
        && str_contains($source['search_page'], "name=\"file_type\"")
        && str_contains($source['search_page'], "'names' => 'Names'")
        && str_contains($source['search_page'], "'imports' => 'Imports'")
        && str_contains($source['search_page'], "'exports' => 'Exports'")
        && str_contains($source['search_page'], "'guid' => 'GUID'")
        && str_contains($source['search_page'], "'md5' => 'MD5'")
        && str_contains($source['search_page'], "'sha1' => 'SHA1'")
        && str_contains($source['search_repository'], 'normalizeFilters')
        && str_contains($source['search_repository'], 'f.extension IN'),
    'Global search must retain its blank/global default while permitting indexed scope and file-type filtering.'
);

$check(
    'names_search_is_indexed',
    str_contains($source['search_repository'], "'ue_name_lookup', 'name_term_id'")
        && str_contains($source['search_repository'], "'Name'")
        && str_contains($source['search_writer'], 'INSERT INTO ue_name_lookup')
        && str_contains($source['lookup_writer'], 'yield (string)($row[\'name_text\'] ?? \'\')')
        && str_contains($source['overflow_writer'], '$add($row[\'name_text\'] ?? \'\')')
        && str_contains($source['name_backfill'], 'CompactTermOverflowWriter')
        && str_contains($source['name_backfill'], "'names'")
        && str_contains($source['name_backfill'], '--all'),
    'Names search must use a compact exact-term projection and provide an existing-catalog backfill.'
);

$check(
    'feedback_limit_500',
    str_contains($source['feedback'], 'CATALOG_FILE_FEEDBACK_MAX_LENGTH = 500')
        && str_contains($source['migration'], 'MODIFY feedback_text VARCHAR(500) NOT NULL')
        && str_contains($source['install'], 'feedback_text VARCHAR(500) NOT NULL'),
    'File feedback UI validation and both upgraded/fresh database schemas must support 500 characters.'
);

$check(
    'dependency_uses_and_used_by',
    str_contains($source['file_info'], "<h2>Uses (")
        && str_contains($source['file_info'], "<h2>Used by (")
        && str_contains($source['dependency_js'], "tab('requires', 'Uses'")
        && str_contains($source['dependency_js'], "tab('required-by', 'Used By'")
        && str_contains($source['dependency_js'], "panel('requires', 'Uses'")
        && str_contains($source['dependency_js'], "panel('required-by', 'Used By'"),
    'File Info and File Examine must clearly show both outgoing Uses and incoming Used By dependency relationships.'
);

$check(
    'fresh_schema_contains_new_tables',
    str_contains($source['migration'], 'ue_transfer_blocked_ips')
        && str_contains($source['migration'], 'ue_name_lookup')
        && str_contains($source['install'], 'CREATE TABLE ue_transfer_blocked_ips')
        && str_contains($source['install'], 'CREATE TABLE ue_name_lookup'),
    'Migration and fresh-install schema must agree on the blocklist and Names search tables.'
);

$syntaxFailures = [];
foreach ($files as $name => $path) {
    if (!str_ends_with($path, '.php')) {
        continue;
    }
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $name . ': could not start php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = $name . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$check('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$node = '';
if (function_exists('shell_exec')) {
    $node = trim((string)@shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}
if ($node !== '') {
    $nodePath = preg_split('/\R/', $node)[0] ?? 'node';
    $pipes = [];
    $process = @proc_open([$nodePath, '--check', $files['dependency_js']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $check('dependency_js_syntax', $exit === 0, trim((string)$stderr . ' ' . (string)$stdout));
    }
}

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
