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
$flagEndpoint = $read('country-flag.php');
$access = $read('access-matrix.php');
$blacklist = $read('site-blacklist.php');
$downloads = $read('download-logs.php');

$checks = [
    'country flags are served as same-origin SVG images' =>
        str_contains($flagEndpoint, 'Content-Type: image/svg+xml')
        && str_contains($flagEndpoint, 'storage/cache/country-flags')
        && str_contains($flagEndpoint, 'lipis/flag-icons@')
        && str_contains($flagEndpoint, 'flags/4x3/'),
    'raw Access Matrix events show SVG flag beside IP' =>
        str_contains($access, 'src="country-flag.php?code=')
        && str_contains($access, 'class="access-country-flag"')
        && str_contains($access, '$countryFlagHtml . catalog_h($ipText)'),
    'raw Access Matrix blacklist control stays compact and right aligned' =>
        str_contains($access, '.access-matrix-ip-line{display:flex;align-items:center;justify-content:space-between;')
        && str_contains($access, 'title="Blacklist IP"')
        && str_contains($access, '>XX</button>'),
    'Most active IPs is collapsed by default' =>
        str_contains($access, '<details class="ui-section access-matrix-collapsible"><summary><div class="ui-section__header"><div><h2>Most active IPs</h2>')
        && !str_contains($access, '<details open class="ui-section access-matrix-collapsible"><summary><div class="ui-section__header"><div><h2>Most active IPs</h2>'),
    'Site blocklist is collapsed by default' =>
        str_contains($access, '<details class="ui-section access-matrix-collapsible"><summary><div class="ui-section__header"><div><h2>Site blocklist</h2>')
        && !str_contains($access, '<details open class="ui-section access-matrix-collapsible"><summary><div class="ui-section__header"><div><h2>Site blocklist</h2>'),
    'raw Access Matrix shows local date above local time' =>
        str_contains($access, "new DateTimeZone('Europe/Dublin')")
        && str_contains($access, '<span class="access-matrix-date">')
        && str_contains($access, '<br><span class="access-matrix-clock">')
        && str_contains($access, "access_matrix_time_html(\$row['occurred_at'])"),
    'site blacklist rows show SVG flag beside IP' =>
        str_contains($blacklist, 'src="country-flag.php?code=')
        && str_contains($blacklist, 'class="site-blacklist-country-flag"')
        && str_contains($blacklist, '$countryFlagHtml . catalog_h((string)$row[\'ip\'])'),
    'site blacklist removal requests also show SVG flag' =>
        substr_count($blacklist, 'src="country-flag.php?code=') >= 2
        && str_contains($blacklist, '$feedbackCountryFlagHtml . catalog_h((string)$row[\'ip\'])'),
    'Download Logs uses SVG flags instead of platform emoji' =>
        substr_count($downloads, 'src="country-flag.php?code=') >= 2
        && str_contains($downloads, 'class="download-country-flag"'),
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
    'country-flag.php',
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
