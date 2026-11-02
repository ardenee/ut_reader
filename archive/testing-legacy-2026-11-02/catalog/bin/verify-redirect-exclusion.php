#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for intentional redirect-target exclusions. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $data = @file_get_contents($path);
    return is_string($data) ? $data : '';
};

$phpFiles = [
    'src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php',
    'src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php',
    'src/Infrastructure/Jobs/CatalogUnsupportedRedirectExclusionJobHandler.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
];
$syntaxFailures = [];
foreach ($phpFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ' could not be linted';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

require_once $root . '/lib/CatalogSupport.php';
require_once $root . '/lib/CatalogRedirectArchive.php';
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;

$record(
    'uz2_target_name_is_known_without_decode',
    CatalogUploadBucketFilePolicy::deterministicRedirectOutputName('FraghouseExtension.ucl.uz2') === 'FraghouseExtension.ucl',
    '.uz2 must expose its target filename from the transport name before zlib/package validation.'
);
$record(
    'uz3_target_name_is_known_without_decode',
    CatalogUploadBucketFilePolicy::deterministicRedirectOutputName('WarrenTemp.upk.uz3') === 'WarrenTemp.upk',
    '.uz3 must expose its target filename from the transport name before zlib/package validation.'
);
$record(
    'redirect_download_duplicate_suffix_is_removed',
    CatalogUploadBucketFilePolicy::deterministicRedirectOutputName('Doorsanc.uax(1).uz2') === 'Doorsanc.uax'
        && CatalogUploadBucketFilePolicy::deterministicRedirectOutputName('Doorsanc.uax (1).uz2') === 'Doorsanc.uax'
        && CatalogUploadBucketFilePolicy::deterministicRedirectOutputName('Map(2).ut2.uz2') === 'Map(2).ut2',
    'A numeric download collision marker after the real package extension must be removed without changing legitimate markers inside the package stem.'
);
$record(
    'classic_uz_requires_embedded_name_probe',
    CatalogUploadBucketFilePolicy::deterministicRedirectOutputName('anything.uz') === null,
    'Classic .uz embeds the authoritative filename and must not be guessed from the outer wrapper name.'
);

$guard = $read('src/Infrastructure/Jobs/CatalogUnsupportedRedirectExclusionJobHandler.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$policy = $read('src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php');
$processor = $read('src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php');

$record(
    'unsupported_targets_finish_as_excluded',
    str_contains($guard, "'status' => 'excluded'")
        && str_contains($guard, 'not a catalogued package type for this upload target')
        && !str_contains($guard, "'status' => 'failed'"),
    'Unsupported redirect target types must complete as intentional exclusions, never failures.'
);
$record(
    'exclusion_happens_before_redirect_handlers',
    str_contains($factory, 'CatalogUnsupportedRedirectExclusionJobHandler')
        && substr_count($factory, 'new CatalogUnsupportedRedirectExclusionJobHandler(') >= 4
        && str_contains($factory, 'JobType::PROCESS_BUCKET_UPLOAD')
        && str_contains($factory, 'JobType::PREPARE_BUCKET_REDIRECT')
        && str_contains($factory, 'JobType::PROCESS_BUCKET_STAGED_PACKAGE')
        && str_contains($factory, 'JobType::IMPORT_STAGED_PACKAGE'),
    'Direct bucket, legacy redirect, archive-member and selected-game package jobs must all pass through the exclusion guard before decompression.'
);
$record(
    'classic_uz_header_is_probed_without_decompression',
    str_contains($guard, 'LEGACY_UZ_HEADER_BYTES = 4096')
        && str_contains($guard, 'catalog_legacy_uz_header($headerBytes)')
        && str_contains($guard, 'file_get_contents($sourcePath, false, null, 0, self::LEGACY_UZ_HEADER_BYTES)'),
    'Classic .uz should inspect only its embedded filename header before deciding whether the target type is intentionally excluded.'
);
$record(
    'transport_extensions_are_not_package_extensions',
    str_contains($policy, 'allowedPackageExtensions()')
        && str_contains($policy, 'ARCHIVE_EXTENSIONS')
        && str_contains($policy, 'array_fill_keys($this->allowedPackageExtensions(), true)'),
    'ZIP/7z/RAR transport support must remain separate from catalogued Unreal package extensions.'
);
$record(
    'decoded_redirect_name_is_cleaned_before_downstream_validation',
    str_contains($processor, 'CatalogUploadBucketFilePolicy::cleanRedirectOutputName(')
        && substr_count($processor, 'CatalogUploadBucketFilePolicy::cleanRedirectOutputName(') >= 2,
    'Both streamed UZ2 and payload-based redirect decoders must normalize the target filename before import extension checks.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
