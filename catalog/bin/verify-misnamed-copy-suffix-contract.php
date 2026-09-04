#!/usr/bin/env php
<?php
/** Contract for explicit browser/download copy-suffix detection in Possible Misnamed Files. */
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
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$detector = $read('src/Infrastructure/Maintenance/CatalogMisnamedFileDetector.php');
$handler = $read('src/Infrastructure/Jobs/CatalogMisnamedFileScanJobHandler.php');
$page = $read('possible-misnamed-files.php');

$record(
    'copy_suffix_1_to_9_is_explicitly_recognized',
    str_contains($detector, 'private static function collisionSuffixBase')
        && str_contains($detector, "preg_match('/^(.*?)[")
        && str_contains($detector, "([1-9])")
        && str_contains($detector, "return ['copy suffix (1-9)', 40, 0]"),
    'Names ending in (1)..(9), optionally preceded by spaces, must be compared against the unsuffixed package identity.'
);

$record(
    'copy_suffix_remains_evidence_based',
    str_contains($detector, '$collisionSuffixMatch = $similarityLabel === \'copy suffix (1-9)\'')
        && str_contains($detector, 'isset($requiredPathHashes[$providerPathHash])')
        && str_contains($detector, '(int)($dependants[$candidateFileId] ?? 0) !== 0'),
    'The suffix alone must never trigger a rename suggestion; unresolved dependency path evidence and orphan-provider checks remain mandatory.'
);

$record(
    'one_exact_object_match_is_enough_for_copy_suffix',
    str_contains($detector, "if (\$matched < 2 && empty(\$group['collision_suffix_match']))")
        && str_contains($detector, '$collisionSuffix && $best >= 1 && $dependants === 0')
        && str_contains($detector, "$confidence = 'high'"),
    'A copy-suffix candidate with exact missing-package/object-path evidence should not be discarded merely because only one object is imported.'
);

$record(
    'scan_policy_version_invalidates_old_resume_state',
    str_contains($handler, "community-path-name-copy-suffix-v4"),
    'Resumed scans created under the old fuzzy-name policy must restart candidate accumulation under the new suffix rule.'
);

$record(
    'operator_page_explains_copy_suffix_check',
    str_contains($page, 'ending in (1) through (9)')
        && str_contains($page, 'MyTex(2).utx')
        && str_contains($page, 'MyTex (2).utx'),
    'The diagnostic page must tell the administrator that copy-suffix names receive explicit testing.'
);

$syntaxFailures = [];
foreach ([
    $root . '/src/Infrastructure/Maintenance/CatalogMisnamedFileDetector.php',
    $root . '/src/Infrastructure/Jobs/CatalogMisnamedFileScanJobHandler.php',
    $root . '/possible-misnamed-files.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
