#!/usr/bin/env php
<?php
/** Read-only contract for global sortable-table header marker layout. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$jsPath = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'catalog-table-sort.js';
$cssPath = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'catalog.css';
$js = @file_get_contents($jsPath);
$css = @file_get_contents($cssPath);
$js = is_string($js) ? $js : '';
$css = is_string($css) ? $css : '';

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'global_sort_indicator_never_consumes_a_new_line',
    str_contains($js, 'position: relative !important; padding-right: 24px !important;')
        && str_contains($js, 'position: absolute !important; right: 8px !important;')
        && str_contains($js, 'top: 50% !important; transform: translateY(-50%) !important;')
        && str_contains($js, 'white-space: nowrap !important;')
        && !str_contains($js, 'display: inline-block !important; margin-left: 7px !important;'),
    'The global ↕/▲/▼ indicator must be positioned inside the header cell without participating in line wrapping.'
);

$record(
    'server_sort_link_keeps_label_and_arrow_together',
    str_contains($css, '.sort-link {')
        && str_contains($css, 'display: inline-block;')
        && str_contains($css, 'white-space: nowrap;'),
    'Server-rendered sortable links must keep their label and ▲/▼ marker on the same line.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
