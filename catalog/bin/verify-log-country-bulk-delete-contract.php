#!/usr/bin/env php
<?php
/** Read-only contract for log country flags and unbounded filtered log deletion. */
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

$core = $read('lib/CatalogSupportCore.php');
$access = $read('access-matrix.php');
$blacklist = $read('site-blacklist.php');
$downloads = $read('download-logs.php');

$checks = [
    'shared country flag helper exists' =>
        str_contains($core, 'function catalog_country_flag(string $countryCode): string')
        && str_contains($core, 'mb_chr(127397 + ord($countryCode[0])'),
    'raw Access Matrix events show country flag beside IP' =>
        str_contains($access, '$countryFlag = catalog_country_flag($countryCode);')
        && str_contains($access, '$countryFlagHtml . catalog_h($ipText)'),
    'site blacklist rows show country flag beside IP' =>
        str_contains($blacklist, '$countryFlag = catalog_country_flag($countryCode);')
        && str_contains($blacklist, '$countryFlagHtml . catalog_h((string)$row[\'ip\'])'),
    'site blacklist removal requests also show country flag' =>
        str_contains($blacklist, '$feedbackCountryFlag = catalog_country_flag($feedbackCountryCode);')
        && str_contains($blacklist, '$feedbackCountryFlagHtml . catalog_h((string)$row[\'ip\'])'),
    'Download Logs listing and delete use one filter builder' =>
        substr_count($downloads, 'download_logs_filter_clause(') >= 3
        && str_contains($downloads, '\'where_sql\' => $where !== [] ? \' WHERE \' . implode(\' AND \', $where) : \'\''),
    'Delete all matching is not page-size limited' =>
        str_contains($downloads, '$action === \'delete_all_matching\'')
        && str_contains($downloads, '\'DELETE a FROM \' . $table . \' a\' . $filter[\'where_sql\']')
        && str_contains($downloads, 'Delete all \' . number_format($total) . \' matching')
        && str_contains($downloads, 'matching record(s) across all pages.')
        && !preg_match('/delete_all_matching.{0,300}(?:LIMIT|1000|500)/s', $downloads),
];

$failures = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failures[] = $label;
    }
}

$syntaxFailures = [];
foreach ([
    'lib/CatalogSupportCore.php',
    'access-matrix.php',
    'site-blacklist.php',
    'download-logs.php',
    __FILE__,
] as $relative) {
    $path = $relative === __FILE__
        ? __FILE__
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
if ($syntaxFailures !== []) {
    $failures[] = 'php_syntax: ' . implode(' | ', $syntaxFailures);
}

$result = [
    'ok' => $failures === [],
    'checks' => count($checks) + 1,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
