<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogParser.php';

function external_fixture_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function external_fixture_issues(object $reader): array
{
    foreach (['getDebugErrors', 'validatePackage'] as $method) {
        if (method_exists($reader, $method)) {
            $issues = $reader->{$method}();
            if (is_array($issues)) {
                return array_values(array_map('strval', $issues));
            }
        }
    }
    return [];
}

function external_fixture_inside(string $path, string $root): bool
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalizedPath = str_replace('\\', '/', $path);
    return str_starts_with($normalizedPath . '/', $normalizedRoot);
}

function external_fixture_relative_path_is_safe(string $path): bool
{
    return $path !== ''
        && !str_starts_with($path, '/')
        && preg_match('/^[A-Za-z]:\//', $path) !== 1
        && preg_match('#(^|/)\.\.(/|$)#', $path) !== 1;
}

/** @return list<array<string,mixed>> */
function external_fixture_manifests(string $root): array
{
    $fixtures = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || $fileInfo->isLink()) {
            continue;
        }
        if (strtolower($fileInfo->getExtension()) !== 'json') {
            continue;
        }
        $decoded = json_decode((string)file_get_contents($fileInfo->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        $entries = is_array($decoded['fixtures'] ?? null) ? $decoded['fixtures'] : [$decoded];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['engine'], $entry['filename'])) {
                continue;
            }
            $entry['_manifest_path'] = $fileInfo->getPathname();
            $fixtures[] = $entry;
        }
    }
    return $fixtures;
}

$rootInput = trim((string)(getenv('UNREALDB_FIXTURE_ROOT') ?: ''));
if ($rootInput === '') {
    fwrite(STDERR, "UNREALDB_FIXTURE_ROOT is required for external package fixtures.\n");
    exit(2);
}
$root = realpath($rootInput);
external_fixture_expect($root !== false && is_dir($root) && is_readable($root), 'External fixture root is unavailable.');

$config = [
    'engine_readers' => [
        'UE1' => ['reader' => '../UE1/UnrealPackageReader.php'],
        'UE2' => ['reader' => '../UE2/UnrealPackageReader.php'],
        'UE3' => ['reader' => '../UE3/UnrealPackageReader.php'],
        'UE4' => ['reader' => '../UE4/UnrealPackageReader.php', 'class' => 'UnrealPackageReader4'],
        'UE5' => ['reader' => '../UE4/UnrealPackageReader.php', 'class' => 'UnrealPackageReader4'],
    ],
];

$fixtures = external_fixture_manifests($root);
external_fixture_expect($fixtures !== [], 'No external fixture manifests containing engine and filename were found.');
$processed = 0;

foreach ($fixtures as $fixture) {
    $manifestPath = (string)$fixture['_manifest_path'];
    $manifestDirectory = dirname($manifestPath);
    $engine = strtoupper(trim((string)$fixture['engine']));
    external_fixture_expect(in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true), basename($manifestPath) . ': unsupported engine ' . $engine);

    $relative = str_replace('\\', '/', trim((string)$fixture['filename']));
    external_fixture_expect(external_fixture_relative_path_is_safe($relative), basename($manifestPath) . ': unsafe fixture filename.');
    $path = realpath($manifestDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    external_fixture_expect($path !== false && is_file($path) && !is_link($path), basename($manifestPath) . ': fixture file is missing or is a symlink.');
    external_fixture_expect(external_fixture_inside($path, $root), basename($manifestPath) . ': fixture escapes UNREALDB_FIXTURE_ROOT.');

    foreach ((array)($fixture['companions'] ?? []) as $companion) {
        $companionRelative = str_replace('\\', '/', trim((string)$companion));
        external_fixture_expect(external_fixture_relative_path_is_safe($companionRelative), basename($manifestPath) . ': unsafe companion filename.');
        $companionPath = realpath($manifestDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $companionRelative));
        external_fixture_expect(
            $companionPath !== false
            && is_file($companionPath)
            && !is_link($companionPath)
            && external_fixture_inside($companionPath, $root),
            basename($manifestPath) . ': companion file is missing, is a symlink, or is outside the fixture root.'
        );
    }

    if (isset($fixture['size'])) {
        external_fixture_expect((int)filesize($path) === (int)$fixture['size'], $relative . ': size differs from the manifest.');
    }
    if (!empty($fixture['sha256'])) {
        external_fixture_expect(hash_file('sha256', $path) === strtolower((string)$fixture['sha256']), $relative . ': SHA-256 differs from the manifest.');
    }

    $class = catalog_load_reader_class($config, $engine);
    $readerOptions = [];
    if (in_array($engine, ['UE4', 'UE5'], true) && is_array($fixture['parser_profile'] ?? null)) {
        $readerOptions['parser_profile'] = $fixture['parser_profile'];
    }
    $reader = $readerOptions !== [] ? new $class($path, $readerOptions) : new $class($path);
    foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
        external_fixture_expect(method_exists($reader, $method), $relative . ': reader is missing ' . $method . '().');
    }
    $header = $reader->getHeader();
    $names = $reader->getNames();
    $imports = $reader->getImports();
    $exports = $reader->getExports();

    foreach ((array)($fixture['header'] ?? []) as $field => $expected) {
        external_fixture_expect(array_key_exists((string)$field, $header), $relative . ': header field is missing: ' . $field);
        external_fixture_expect($header[$field] === $expected, $relative . ': header field differs: ' . $field);
    }
    if (isset($fixture['names'])) {
        if (is_array($fixture['names'])) {
            $actualNames = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $names);
            external_fixture_expect($actualNames === array_values(array_map('strval', $fixture['names'])), $relative . ': names table differs.');
        } else {
            external_fixture_expect(count($names) === (int)$fixture['names'], $relative . ': names count differs.');
        }
    }
    if (isset($fixture['imports'])) {
        external_fixture_expect(count($imports) === (int)$fixture['imports'], $relative . ': imports count differs.');
    }
    if (isset($fixture['exports'])) {
        external_fixture_expect(count($exports) === (int)$fixture['exports'], $relative . ': exports count differs.');
    }

    $issues = external_fixture_issues($reader);
    $expectedIssue = trim((string)($fixture['issue_contains'] ?? ''));
    if ($expectedIssue !== '') {
        external_fixture_expect(
            count(array_filter($issues, static fn(string $issue): bool => str_contains($issue, $expectedIssue))) > 0,
            $relative . ': expected reader issue was not found.'
        );
    } elseif (empty($fixture['allow_issues'])) {
        external_fixture_expect($issues === [], $relative . ': unexpected reader issue: ' . implode(' | ', $issues));
    }

    $processed++;
    fwrite(STDOUT, sprintf("PASS %-4s %s (%d names / %d imports / %d exports)\n", $engine, $relative, count($names), count($imports), count($exports)));
}

fwrite(STDOUT, 'External reader fixtures passed: ' . $processed . " package(s).\n");
