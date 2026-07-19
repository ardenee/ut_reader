<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogParser.php';
require_once __DIR__ . '/fixtures/SyntheticReaderFixtures.php';

function reader_fixture_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function reader_fixture_issues(object $reader): array
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

function reader_fixture_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            reader_fixture_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$manifestPath = dirname(__DIR__, 2) . '/tests/fixtures/synthetic-readers.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$fixtures = is_array($manifest['fixtures'] ?? null) ? $manifest['fixtures'] : [];
reader_fixture_expect(count($fixtures) === 6, 'Synthetic reader fixture manifest is incomplete.');
$manifestIds = array_map(static fn(array $fixture): string => (string)($fixture['id'] ?? ''), $fixtures);
reader_fixture_expect($manifestIds === SyntheticReaderFixtures::ids(), 'Synthetic reader fixture manifest and generator IDs differ.');

$config = [
    'engine_readers' => [
        'UE1' => ['reader' => '../UE1/UnrealPackageReader.php'],
        'UE2' => ['reader' => '../UE2/UnrealPackageReader.php'],
        'UE3' => ['reader' => '../UE3/UnrealPackageReader.php'],
        'UE4' => ['reader' => '../UE4/UnrealPackageReader.php', 'class' => 'UnrealPackageReader4'],
    ],
];

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-reader-fixtures-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create synthetic reader fixture directory.');
}

try {
    foreach ($fixtures as $fixture) {
        reader_fixture_expect(is_array($fixture), 'Invalid reader fixture manifest entry.');
        $id = (string)($fixture['id'] ?? '');
        $engine = strtoupper((string)($fixture['engine'] ?? ''));
        $filename = basename((string)($fixture['filename'] ?? ''));
        reader_fixture_expect($id !== '' && $filename !== '' && in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4'], true), 'Reader fixture identity is invalid.');

        $bytes = SyntheticReaderFixtures::build($id);
        reader_fixture_expect(strlen($bytes) === (int)$fixture['size'], $id . ': fixture size changed.');
        reader_fixture_expect(hash('sha256', $bytes) === (string)$fixture['sha256'], $id . ': fixture SHA-256 changed.');

        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        reader_fixture_expect(file_put_contents($path, $bytes) === strlen($bytes), $id . ': fixture could not be written.');
        $class = catalog_load_reader_class($config, $engine);
        $reader = $engine === 'UE4'
            ? new $class($path, ['parser_profile' => [
                'profile_key' => 'standard-ue4',
                'label' => 'Standard UE4 package parser',
                'assumed_unversioned_parser_version' => 511,
            ]])
            : new $class($path);

        foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
            reader_fixture_expect(method_exists($reader, $method), $id . ': reader is missing ' . $method . '().');
        }
        $header = $reader->getHeader();
        $names = $reader->getNames();
        $imports = $reader->getImports();
        $exports = $reader->getExports();
        reader_fixture_expect(is_array($header) && is_array($names) && is_array($imports) && is_array($exports), $id . ': reader returned invalid table data.');

        foreach ((array)($fixture['header'] ?? []) as $key => $expected) {
            reader_fixture_expect(array_key_exists((string)$key, $header), $id . ': header field is missing: ' . $key);
            reader_fixture_expect($header[$key] === $expected, $id . ': header field changed: ' . $key);
        }

        $actualNames = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $names);
        reader_fixture_expect($actualNames === (array)$fixture['names'], $id . ': name table changed.');
        reader_fixture_expect(count($imports) === 1 && (string)($imports[0]['objectNameText'] ?? '') === (string)$fixture['import_object'], $id . ': import table changed.');
        reader_fixture_expect(count($exports) === 1 && (string)($exports[0]['objectNameText'] ?? '') === (string)$fixture['export_object'], $id . ': export table changed.');

        $issues = reader_fixture_issues($reader);
        $expectedIssue = $fixture['issue_contains'] ?? null;
        if (is_string($expectedIssue) && $expectedIssue !== '') {
            reader_fixture_expect(
                count(array_filter($issues, static fn(string $issue): bool => str_contains($issue, $expectedIssue))) > 0,
                $id . ': expected parser-profile issue was not recorded.'
            );
        } else {
            reader_fixture_expect($issues === [], $id . ': unexpected parser issue: ' . implode(' | ', $issues));
        }

        $headerOnly = catalog_try_read_package_header($config, $engine, $path);
        reader_fixture_expect((string)($headerOnly['guid'] ?? '') === (string)($fixture['header']['guid'] ?? ''), $id . ': catalog header adapter changed the package GUID.');
    }

    foreach (['UE1' => 'Malformed.u', 'UE2' => 'Malformed.ut2', 'UE3' => 'Malformed.ut3', 'UE4' => 'Malformed.uasset'] as $engine => $filename) {
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, SyntheticReaderFixtures::malformed());
        $class = catalog_load_reader_class($config, $engine);
        $reader = new $class($path);
        $header = $reader->getHeader();
        reader_fixture_expect((int)($header['signature'] ?? 0) === 0, $engine . ': malformed package did not fail closed.');
        reader_fixture_expect(reader_fixture_issues($reader) !== [], $engine . ': malformed package did not record a parser issue.');
        reader_fixture_expect($reader->getNames() === [] && $reader->getImports() === [] && $reader->getExports() === [], $engine . ': malformed package exposed partial tables.');
    }

    fwrite(STDOUT, "Synthetic UE1-UE4 reader fixture tests passed.\n");
} finally {
    reader_fixture_remove_tree($directory);
}
