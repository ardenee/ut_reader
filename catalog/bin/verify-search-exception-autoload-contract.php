#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for catalogue-search exception PSR-4 loading. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$exceptionPath = $root . '/src/Application/Search/CatalogSearchUnavailableException.php';
$servicePath = $root . '/src/Application/Search/CatalogSearchService.php';
$legacyPath = $root . '/lib/CatalogSearchService.php';
$service = (string)@file_get_contents($servicePath);
$legacy = (string)@file_get_contents($legacyPath);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$syntaxFailures = [];
foreach ([$exceptionPath, $servicePath, $legacyPath] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ' could not be linted';
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$record(
    'exception_has_dedicated_psr4_file',
    is_file($exceptionPath)
        && !str_contains($service, 'class CatalogSearchUnavailableException'),
    'CatalogSearchUnavailableException must live in its own PSR-4 file rather than being hidden inside CatalogSearchService.php.'
);

$record(
    'legacy_facade_aliases_namespaced_exception',
    str_contains($legacy, 'use UnrealDb\\Catalog\\Application\\Search\\CatalogSearchUnavailableException as ApplicationSearchUnavailableException;')
        && str_contains($legacy, "class_alias(ApplicationSearchUnavailableException::class, 'CatalogSearchUnavailableException');"),
    'The legacy global exception alias must resolve through the namespaced PSR-4 class.'
);

require_once $root . '/bootstrap/autoload.php';
$record(
    'exception_autoloads_before_service',
    class_exists(\UnrealDb\Catalog\Application\Search\CatalogSearchUnavailableException::class),
    'Autoloading the exception directly must not depend on CatalogSearchService being loaded first.'
);
$record(
    'service_still_autoloads',
    class_exists(\UnrealDb\Catalog\Application\Search\CatalogSearchService::class),
    'Splitting the exception class must not break service autoloading.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
