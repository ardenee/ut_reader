#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut99SnapshotBuilder;

$options = getopt('', ['summary']);
$app = catalog_bootstrap();
$db = $app->db;
$config = catalog_config();
$storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");

$table = $db->query(
    "SELECT COUNT(*) FROM information_schema.tables"
    . " WHERE table_schema=DATABASE() AND table_name='ue_uedb5_files'"
);
if ((int)$table->fetchColumn() !== 1) {
    fwrite(STDERR, "Required Step 5 table is missing: ue_uedb5_files\n");
    exit(2);
}

$game = $db->query("SELECT id FROM ue_games WHERE slug='ut99' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "UT99 game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];
$reader = new Uedb5MetadataReader($storage);

$statement = $db->prepare(
    'SELECT file_id,source_policy FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id'
);
$statement->execute([$gameId]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$contextFiles = [];
$policyFiles = [];
$details = [];
$referencedNameRows = 0;

$fnameIndex = static function (mixed $value): ?int {
    if (!is_array($value)) {
        return null;
    }
    $index = $value['name_index'] ?? $value['index'] ?? null;
    return $index === null || $index === '' ? null : (int)$index;
};
$flagsInt = static function (mixed $value): int {
    if (is_int($value)) {
        return $value;
    }
    $text = trim((string)$value);
    if ($text === '') {
        return 0;
    }
    return preg_match('/^[0-9A-Fa-f]+$/', $text) === 1
        ? (int)hexdec($text)
        : (int)$text;
};
$expectedPolicy = static function (int $version, int $licensee): string {
    if ($version > 69) {
        return Uedb5Ut99SnapshotBuilder::POLICY_FORWARD_COMPAT;
    }
    if ($version <= 68 && $licensee === 0) {
        return Uedb5Ut99SnapshotBuilder::POLICY_RETAIL;
    }
    return Uedb5Ut99SnapshotBuilder::POLICY_SUPPLEMENTAL;
};

foreach ($rows as $registration) {
    $fileId = (int)$registration['file_id'];
    $summary = $reader->page($gameId, $fileId, 'summary', 0, 1)[0] ?? null;
    if (!is_array($summary)) {
        throw new RuntimeException("UEDB5 summary is missing for UT99 file #$fileId.");
    }
    $version = (int)($summary['package_version'] ?? -1);
    $licensee = (int)($summary['licensee_version'] ?? 0);
    $expected = $expectedPolicy($version, $licensee);
    $actual = (string)($registration['source_policy'] ?? '');
    $policyChanged = $actual !== $expected;
    if ($policyChanged) {
        $policyFiles[] = $fileId;
    }

    $referenced = [];
    foreach ($reader->scan($gameId, $fileId, 'imports') as $import) {
        foreach (['class_package','class_name','object_name','object_package'] as $field) {
            $index = $fnameIndex($import[$field] ?? null);
            if ($index !== null && $index >= 0) {
                $referenced[$index] = true;
            }
        }
    }
    foreach ($reader->scan($gameId, $fileId, 'exports') as $export) {
        $index = $fnameIndex($export['object_name'] ?? null);
        if ($index !== null && $index >= 0) {
            $referenced[$index] = true;
        }
    }

    $filtered = [];
    if ($referenced !== []) {
        $nameRows = $reader->rowsByPositions(
            $gameId,
            $fileId,
            'names',
            array_keys($referenced)
        );
        $referencedNameRows += count($nameRows);
        foreach ($nameRows as $position => $name) {
            if (($flagsInt($name['flags'] ?? 0) & CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS) === 0) {
                $filtered[] = (int)($name['index'] ?? $position);
            }
        }
    }
    if ($filtered !== []) {
        $contextFiles[] = $fileId;
    }

    if ($filtered !== [] || $policyChanged) {
        $details[(string)$fileId] = [
            'package_version' => $version,
            'licensee_version' => $licensee,
            'context_filtered_name_indexes' => $filtered,
            'source_policy_current' => $actual,
            'source_policy_expected' => $expected,
        ];
    }
}

$contextFiles = array_values(array_unique($contextFiles));
$policyFiles = array_values(array_unique($policyFiles));
sort($contextFiles, SORT_NUMERIC);
sort($policyFiles, SORT_NUMERIC);
$impacted = array_values(array_unique(array_merge($contextFiles, $policyFiles)));
sort($impacted, SORT_NUMERIC);

$result = [
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'staged_file_count' => count($rows),
    'referenced_name_rows_examined' => $referencedNameRows,
    'context_filter_file_count' => count($contextFiles),
    'source_policy_refresh_file_count' => count($policyFiles),
    'impacted_file_count' => count($impacted),
    'requires_original_package_read' => false,
];
if (!isset($options['summary'])) {
    $result['context_filter_file_ids'] = $contextFiles;
    $result['source_policy_refresh_file_ids'] = $policyFiles;
    $result['impacted_file_ids'] = $impacted;
    $result['details'] = $details;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
