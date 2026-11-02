#!/usr/bin/env php
<?php
/** Read-only contract for source-mirror validation of corrupt verified packages. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$source = @file_get_contents($root . '/bin/verify-corrupt-files-against-source-mirror.php');
$source = is_string($source) ? $source : '';
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'diagnostic_is_read_only',
    !str_contains($source, 'DELETE FROM ue_files')
        && !str_contains($source, 'UPDATE ue_files')
        && !str_contains($source, 'INSERT INTO ue_files')
        && !str_contains($source, '--apply'),
    'Mirror comparison must never mutate catalog rows or verified storage.'
);

$record(
    'nested_archive_provenance_is_resolved_with_production_extractor',
    str_contains($source, 'CatalogArchiveExtractor')
        && str_contains($source, 'CatalogArchiveExtractor::isArchiveName')
        && str_contains($source, '->entries(')
        && str_contains($source, '->extractToTemp(')
        && str_contains($source, 'source_chain'),
    'Recorded source-relative paths may traverse nested ZIP/RAR/7z/UMOD-family containers and must use the production extractor.'
);

$record(
    'nested_archive_wrapper_directories_are_tolerated_safely',
    str_contains($source, 'count($basenameNested) === 1')
        && str_contains($source, 'strcasecmp(basename($entryPath), (string)$remaining[0]) === 0')
        && str_contains($source, '$nestedParts = 1'),
    'Historical provenance may omit a wrapper directory inside an archive; only a unique next-archive basename may be used as a safe fallback.'
);

$record(
    'archive_runtime_capabilities_are_reported',
    str_contains($source, "'archive_capabilities' => CatalogArchiveExtractor::runtimeCapabilities()"),
    'The diagnostic must report archive decoder capabilities from the live PHP runtime.'
);

$record(
    'mirror_bytes_are_compared_exactly',
    str_contains($source, 'md5_file(')
        && str_contains($source, 'sha1_file(')
        && str_contains($source, 'source_exact_catalog_bytes')
        && str_contains($source, "'catalog_size'"),
    'The diagnostic must distinguish original-source corruption from later storage corruption using exact size/MD5/SHA1.'
);

$record(
    'source_copy_is_revalidated_with_current_reader',
    str_contains($source, 'CatalogVerifiedPackageInspector')
        && str_contains($source, 'source_valid_now')
        && str_contains($source, 'source_validation_error'),
    'A source copy that differs from the catalogued bytes must still pass the current production package reader before it can be considered a repair source.'
);

$record(
    'game_scope_targets_current_failed_full_sync_files',
    str_contains($source, 'JobType::FULL_SYNC_FILE')
        && str_contains($source, 'j.status IN ("failed","dead_letter")')
        && str_contains($source, '--file-ids=... or --game-id=...'),
    'A game-scoped run must stay bounded to currently failed Full Sync package children rather than scan every package.'
);

$syntaxOk = true;
$syntaxDetail = '';
if (function_exists('proc_open')) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-l', $root . '/bin/verify-corrupt-files-against-source-mirror.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $syntaxOk = proc_close($process) === 0;
        $syntaxDetail = trim((string)$stderr . ' ' . (string)$stdout);
    } else {
        $syntaxOk = false;
        $syntaxDetail = 'Could not start PHP lint.';
    }
}
$record('php_syntax', $syntaxOk, $syntaxOk ? '' : $syntaxDetail);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
