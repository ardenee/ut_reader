#!/usr/bin/env php
<?php
/** Read-only contract for main search styling and partial-IP log filtering. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$index = (string)@file_get_contents($root . '/index.php');
$logs = (string)@file_get_contents($root . '/download-logs.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$record(
    'search_uses_standard_page_and_filter_components',
    str_contains($index, 'catalog_page_header(')
        && preg_match('/catalog_page_header\\s*\\(\\s*[\'"]Search[\'"]\\s*,/m', $index) === 1
        && str_contains($index, 'class="ui-filter-bar catalog-search-filter"')
        && str_contains($index, 'class="ui-field catalog-search-query"')
        && str_contains($index, 'class="ui-input"')
        && str_contains($index, 'class="ui-select"')
        && str_contains($index, 'ui-button ui-button--primary'),
    'Main search must use the same page header, field, filter-bar and button components as the rest of the site.'
);

$record(
    'search_checkboxes_are_compact_and_evenly_spaced',
    str_contains($index, '.catalog-search-scope-list{display:flex;gap:8px 16px')
        && str_contains($index, '.catalog-search-scope input[type=checkbox]{width:16px;height:16px')
        && !str_contains($index, '<div class="card hero"><h1>Search</h1>'),
    'Search scope checkboxes must use compact native sizing rather than generic text-input/card styling.'
);

$record(
    'download_logs_table_is_compact_and_primary_content_gets_space',
    str_contains($logs, '.download-log-table{min-width:1080px;table-layout:auto}')
        && str_contains($logs, '.download-log-time,.download-log-status,.download-log-ip,.download-log-country,.download-log-transfer,.download-log-job')
        && str_contains($logs, 'width:1%;white-space:nowrap')
        && str_contains($logs, '.download-log-file{width:auto;min-width:260px')
        && str_contains($logs, '.download-log-agent{width:30ch;min-width:30ch;max-width:30ch}')
        && str_contains($logs, 'download-log-agent-text')
        && str_contains($logs, 'text-overflow:ellipsis'),
    'Download Logs metadata columns must stay compact while File/package gets remaining width and long user agents are truncated instead of making rows enormous.'
);

$record(
    'download_logs_accept_partial_ip',
    str_contains($logs, 'INET6_NTOA(')
        && str_contains($logs, 'LIKE ? ESCAPE')
        && str_contains($logs, 'placeholder="Full or partial IP"')
        && !str_contains($logs, 'Enter a valid IPv4 or IPv6 address.'),
    'Download Logs IP filtering must accept fragments/prefixes while retaining exact binary matching for a complete valid IP.'
);

$syntaxFailures = [];
foreach (['index.php','download-logs.php'] as $relative) {
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
