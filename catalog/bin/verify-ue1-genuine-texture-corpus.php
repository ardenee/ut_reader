#!/usr/bin/env php
<?php
/**
 * UE1 O01/O02 genuine-file corpus verification (read-only).
 *
 * Corpus inventory: catalog/compliance/inventory/ue1-textures-v35-v61.csv
 * Native source: Unreal v1.200 Core/Src/UnLinker.h:303-339,454-478.
 * Only tests the canonical UE1 reader's existing header/name/import/export
 * decoding and routed header helper. Does not assert independent C++ parity.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
require_once dirname(__DIR__) . '/lib/CatalogParser.php';
require_once dirname(__DIR__) . '/src/Application/Catalog/CatalogPackageHeaderInspector.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;

$repo = dirname(__DIR__);
$root = getenv('UE1_GENUINE_TEXTURE_ROOT') ?: 'L:\\Source\\Engine\\UE1\\Source\\Unreal\\Textures';
$manifest = $repo . '/compliance/inventory/ue1-textures-v35-v61.csv';
$fh = fopen($manifest, 'rb');
if ($fh === false) { throw new RuntimeException('Missing corpus inventory: ' . $manifest); }
// Windows PowerShell's Export-Csv writes a UTF-8 BOM; skip it before CSV parsing.
if (fread($fh, 3) !== "\xEF\xBB\xBF") { rewind($fh); }
$columns = fgetcsv($fh, 0, ',', '"', '');
$expectedColumns = ['RelativePath', 'SizeBytes', 'PackageVersion', 'LicenseeVersion', 'Sha256'];
if ($columns !== $expectedColumns) {
    throw new RuntimeException('Unexpected inventory columns: ' . json_encode($columns));
}
$results = [];
while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if (count($line) !== count($columns)) { throw new RuntimeException('Invalid inventory row.'); }
    $record = array_combine($columns, $line);
    $relative = (string)$record['RelativePath'];
    $path = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    $issues = [];
    if (!is_file($path)) {
        $issues[] = 'Missing genuine package: ' . $path;
        $results[] = ['file' => $relative, 'version' => (int)$record['PackageVersion'], 'nameCount' => null, 'importCount' => null, 'exportCount' => null, 'issues' => $issues];
        continue;
    }
    $size = filesize($path);
    if ($size !== (int)$record['SizeBytes']) { $issues[] = 'Manifest size differs'; }
    if (strtolower((string)hash_file('sha256', $path)) !== strtolower((string)$record['Sha256'])) {
        $issues[] = 'Manifest SHA-256 differs';
    }
    // Independent direct-byte interpretation of Epic v1.200's pre-68
    // FPackageFileSummary field order, without using UnrealDB's parser.
    $raw44 = file_get_contents($path, false, null, 0, 44);
    $sourceSummary = is_string($raw44) && strlen($raw44) === 44
        ? unpack('Vtag/Vpacked/Vflags/VnameCount/VnameOffset/VexportCount/VexportOffset/VimportCount/VimportOffset/VheritageCount/VheritageOffset', $raw44)
        : false;
    if (!is_array($sourceSummary)) { $issues[] = 'Cannot decode source-defined 44-byte summary'; }
    $reader = new CatalogUE1PackageReader($path);
    foreach ($reader->validatePackage() as $error) { $issues[] = 'Parse: ' . $error; }
    $h = $reader->getHeader();
    if ($issues === []) {
        $map = [
            'tag' => 'signature', 'packed' => 'packedVersion',
            'flags' => 'pkgFlags', 'nameCount' => 'nameCount',
            'nameOffset' => 'nameOffset', 'exportCount' => 'exportCount',
            'exportOffset' => 'exportOffset', 'importCount' => 'importCount',
            'importOffset' => 'importOffset', 'heritageCount' => 'heritageCount',
            'heritageOffset' => 'heritageOffset',
        ];
        foreach ($map as $serializedField => $parsedField) {
            if ((int)$sourceSummary[$serializedField] !== (int)($h[$parsedField] ?? -1)) {
                $issues[] = 'Independent source-summary mismatch: ' . $serializedField;
            }
        }
        if ((int)($h['signature'] ?? 0) !== 0x9E2A83C1) { $issues[] = 'Bad source-defined standard magic'; }
        if ((int)($h['version'] ?? -1) !== (int)$record['PackageVersion']) { $issues[] = 'Version mismatch'; }
        if ((int)($h['licensee'] ?? -1) !== (int)$record['LicenseeVersion']) { $issues[] = 'Licensee mismatch'; }
        if (count($reader->getNames()) !== (int)($h['nameCount'] ?? -1)) { $issues[] = 'Name count mismatch'; }
        if (count($reader->getImports()) !== (int)($h['importCount'] ?? -1)) { $issues[] = 'Import count mismatch'; }
        if (count($reader->getExports()) !== (int)($h['exportCount'] ?? -1)) { $issues[] = 'Export count mismatch'; }
        try {
            $routed = catalog_try_read_package_header([], 'UE1', $path);
            if ((int)($routed['version'] ?? -1) !== (int)$record['PackageVersion']) {
                $issues[] = 'Production header helper disagrees';
            }
        } catch (Throwable $error) {
            $issues[] = 'Production helper: ' . $error->getMessage();
        }
        // Epic UT99 v400 FPackageFileSummary: for version <68 the GUID
        // comes from the final heritage-table record, never inline at 44.
        $inspection = CatalogPackageHeaderInspector::inspect($path, ['engine_key' => 'UE1', 'extension' => 'utx']);
        if (!($inspection['ok'] ?? false)) {
            $issues[] = 'File-examine inspector rejected a valid genuine header';
        } elseif ((string)($inspection['summary']['GUID'] ?? '') !== (string)($h['guid'] ?? '')) {
            $issues[] = 'File-examine inspector GUID disagrees with source-defined heritage GUID: inspector='
                . (string)($inspection['summary']['GUID'] ?? '') . ' canonical=' . (string)($h['guid'] ?? '');
        }
    }
    $results[] = [
        'file' => $relative,
        'version' => (int)$record['PackageVersion'],
        'nameCount' => $h['nameCount'] ?? null,
        'importCount' => $h['importCount'] ?? null,
        'exportCount' => $h['exportCount'] ?? null,
        'issues' => $issues,
    ];
}
fclose($fh);
$failed = array_values(array_filter($results, static fn(array $r) => $r['issues'] !== []));
$distribution = [];
foreach ($results as $r) {
    $key = (string)$r['version'];
    if (!isset($distribution[$key])) { $distribution[$key] = ['total' => 0, 'passed' => 0]; }
    $distribution[$key]['total']++;
    if ($r['issues'] === []) { $distribution[$key]['passed']++; }
}
uksort($distribution, static fn($a, $b) => (int)$a <=> (int)$b);
echo json_encode([
    'ok' => $failed === [] && count($results) === 61,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'by_version' => $distribution,
    'failed_files' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
exit($failed === [] && count($results) === 61 ? 0 : 1);
