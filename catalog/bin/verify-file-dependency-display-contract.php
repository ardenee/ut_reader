#!/usr/bin/env php
<?php
/** Read-only contract for file Uses / Used By relationship display. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$endpoint = $read('file-dependency-files.php');
$display = $read('assets/file-dependency-display.js');
$support = $read('lib/CatalogSupportCore.php');
$examine = $read('file-examine-paged-core.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$record(
    'used_by_seeds_authoritative_resolved_links',
    str_contains($endpoint, 'catalog_dependency_used_by_rows($db, $fileId, 5000)')
        && str_contains($endpoint, '$requiredBy[$directId] = fd_file_payload($directUsedBy);'),
    'Used By must always include files whose compact dependency link resolves directly to the examined file.'
);

$record(
    'alias_reverse_matching_prefers_examined_target',
    str_contains($endpoint, 'int $excludeFileId = 0')
        && str_contains($endpoint, 'int $preferredFileId = 0')
        && str_contains($endpoint, '(int)$row[\'source_file_id\'],')
        && str_contains($endpoint, '$fileId')
        && str_contains($endpoint, '$requiredBy[$sourceId] = fd_file_payload($row);'),
    'Reverse alias/package recovery must prefer the examined target and key the result by the importing source file.'
);

$record(
    'uses_does_not_resolve_to_self',
    str_contains($endpoint, 'fd_select_candidate(')
        && str_contains($endpoint, "(string)\$dependency['required_package']")
        && str_contains($endpoint, '$fileId')
        && str_contains($endpoint, "(int)\$resolved['id'] !== \$fileId"),
    'Uses must exclude the current file when selecting an unresolved package candidate.'
);

$record(
    'paged_file_examine_hosts_uses_and_used_by',
    str_contains($examine, 'data-file-examine-native-panel')
        && str_contains($display, "root.querySelector('[data-file-examine-native-panel]')")
        && str_contains($display, "tab('requires', 'Uses'")
        && str_contains($display, "tab('required-by', 'Used By'")
        && str_contains($display, 'nativePanel.hidden = true;')
        && str_contains($display, 'nativePanel.hidden = false;')
        && !str_contains($display, "root.querySelector('[data-panel=\"exports\"]')"),
    'The current paged file examiner must host Uses / Used By tabs without depending on the retired all-panels examiner DOM.'
);

$record(
    'file_examine_links_to_package_details',
    str_contains($examine, '>Package details</a>')
        && str_contains($examine, "'Package' => '<a href=\"file-info.php?id=' . \$fileId"),
    'file-examine.php must provide an explicit link back to the package-details page, matching file-info.php navigation in the opposite direction.'
);

$record(
    'dependency_failures_are_visible',
    str_contains($display, 'showDependencyLoadError(error)')
        && str_contains($display, 'Uses / Used By unavailable:')
        && str_contains($display, 'file-dependency-load-error'),
    'A dependency endpoint failure must render on the page instead of disappearing into the browser console.'
);

$record(
    'file_examine_auto_loads_relationship_display',
    str_contains($support, "['file-info.php', 'file-examine.php']")
        && str_contains($support, 'assets/file-dependency-display.js'),
    'file-examine.php and file-info.php must automatically load the Uses / Used By display.'
);

$syntaxFailures = [];
foreach (['file-dependency-files.php','file-examine-paged-core.php'] as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
