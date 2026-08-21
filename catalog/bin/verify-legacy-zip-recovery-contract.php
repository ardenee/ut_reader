#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for legacy ZIP compatibility routing. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogSequentialArchiveReader;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$sequentialPath = $root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php';
$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$sequentialSource = (string)@file_get_contents($sequentialPath);
$handlerSource = (string)@file_get_contents($handlerPath);

$syntaxFailures = [];
foreach ([$sequentialPath, $handlerPath] as $path) {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . implode(' ', $output);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$record(
    'zip_stream_capability_is_probed_before_random_access',
    str_contains($sequentialSource, "getStreamIndex($index, \\ZipArchive::FL_UNCHANGED)")
        && str_contains($sequentialSource, 'if (!is_resource($stream))')
        && str_contains($sequentialSource, 'return true;'),
    'A ZIP which can be listed but cannot open one of its member streams must switch to the PHP libarchive sequential path.'
);

$record(
    'zip_sequential_backend_is_available',
    str_contains($sequentialSource, "'zip' => $this->definedFormats(['libarchive\\\\FORMAT_ZIP'])")
        && str_contains($sequentialSource, '$archive->currentEntryStream()'),
    'Legacy ZIP recovery must stay in-process through ext-archive/libarchive.'
);

$record(
    'control_character_metadata_does_not_create_partial_archive',
    str_contains($sequentialSource, 'if ($this->hasControlCharacters($rawPath))')
        && str_contains($sequentialSource, 'continue;')
        && str_contains($sequentialSource, "preg_match('/[\\x00-\\x1F\\x7F]/u', $path) === 1"),
    'Unrepresentable control-character members such as classic Mac Icon metadata must be ignored rather than retained as retryable failures.'
);

$record(
    'archive_members_use_container_not_browser_ingress_limit',
    substr_count($handlerSource, '$entryLimit = $this->containerLimitBytes();') >= 2
        && !str_contains($handlerSource, "$entryLimit = $extension === 'pak' ? $this->containerLimitBytes() : $this->normalLimitBytes();"),
    'A server-side archive member is bounded by archive/container policy and total expansion, not the ordinary browser upload-size limit.'
);

require_once $root . '/bootstrap/autoload.php';

if (class_exists(\ZipArchive::class)) {
    $temporary = tempnam(sys_get_temp_dir(), 'unrealdb-zip-contract-');
    if (!is_string($temporary)) {
        $record('legacy_zip_control_character_fixture', false, 'Could not allocate ZIP fixture.');
    } else {
        @unlink($temporary);
        $zipPath = $temporary . '.zip';
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            $record('legacy_zip_control_character_fixture', false, 'Could not create ZIP fixture.');
        } else {
            $zip->addFromString("Folder/Icon\r", 'legacy metadata');
            $zip->addFromString('Folder/Test.unr', str_repeat('U', 64));
            $zip->close();
            try {
                $reader = new CatalogSequentialArchiveReader(['archive' => ['max_entries' => 100]]);
                $record(
                    'legacy_zip_control_character_fixture',
                    $reader->shouldUse($zipPath, 'fixture.zip'),
                    'A ZIP containing an unrepresentable classic-Mac metadata filename must select the compatibility reader.'
                );
            } catch (Throwable $error) {
                $record('legacy_zip_control_character_fixture', false, $error->getMessage());
            } finally {
                @unlink($zipPath);
            }
        }
    }
} else {
    $record(
        'legacy_zip_control_character_fixture',
        true,
        'ZipArchive is not loaded in this verifier runtime; static compatibility checks still apply.'
    );
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
