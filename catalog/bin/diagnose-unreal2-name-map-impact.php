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
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;

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

$game = $db->query("SELECT id FROM ue_games WHERE slug='unreal2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "Unreal II game registration was not found.\n");
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
$truncateFiles = [];
$policyFiles = [];
$serialOffsetReparseFiles = [];
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
$expectedPolicy = static function (int $version): string {
    if ($version >= 60 && $version <= 69) {
        return Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000;
    }
    if ($version <= 126) {
        return Uedb5Unreal2SnapshotBuilder::POLICY_V126_GENERIC;
    }
    return Uedb5Unreal2SnapshotBuilder::POLICY_POST_V126_UNRESOLVED;
};

foreach ($rows as $registration) {
    $fileId = (int)$registration['file_id'];
    $summary = $reader->page($gameId, $fileId, 'summary', 0, 1)[0] ?? null;
    if (!is_array($summary)) {
        throw new RuntimeException("UEDB5 summary is missing for Unreal II file #$fileId.");
    }
    $version = (int)($summary['package_version'] ?? -1);
    $actualPolicy = (string)($registration['source_policy'] ?? '');
    $expected = $expectedPolicy($version);
    $policyChanged = $actualPolicy !== $expected;
    if ($policyChanged) {
        $policyFiles[] = $fileId;
    }

    $referenced = [];
    foreach ($reader->scan($gameId, $fileId, 'imports') as $import) {
        foreach (['class_package','class_name','object_name'] as $field) {
            $index = $fnameIndex($import[$field] ?? null);
            if ($index !== null && $index >= 0) {
                $referenced[$index] = true;
            }
        }
    }

    $negativeSerialSize = false;
    foreach ($reader->scan($gameId, $fileId, 'exports') as $export) {
        $index = $fnameIndex($export['object_name'] ?? null);
        if ($index !== null && $index >= 0) {
            $referenced[$index] = true;
        }
        if ((int)($export['serial_size'] ?? 0) < 0) {
            $negativeSerialSize = true;
        }
    }
    if ($negativeSerialSize) {
        $serialOffsetReparseFiles[] = $fileId;
    }

    $filtered = [];
    $truncated = [];
    if ($version >= 60 && $version <= 126 && $referenced !== []) {
        $nameRows = $reader->rowsByPositions($gameId, $fileId, 'names', array_keys($referenced));
        $referencedNameRows += count($nameRows);
        foreach ($nameRows as $position => $name) {
            $index = (int)($name['index'] ?? $position);
            if (($flagsInt($name['flags'] ?? 0) & CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS) === 0) {
                $filtered[] = $index;
            }
            if ($version >= 70
                && mb_strlen((string)($name['text'] ?? ''), 'UTF-8') > 63) {
                $truncated[] = $index;
            }
        }
    }
    if ($filtered !== []) {
        $contextFiles[] = $fileId;
    }
    if ($truncated !== []) {
        $truncateFiles[] = $fileId;
    }

    if ($filtered !== [] || $truncated !== [] || $policyChanged || $negativeSerialSize) {
        $details[(string)$fileId] = [
            'package_version' => $version,
            'context_filtered_name_indexes' => $filtered,
            'runtime_truncated_name_indexes' => $truncated,
            'negative_serial_size_requires_pass1_reparse' => $negativeSerialSize,
            'source_policy_current' => $actualPolicy,
            'source_policy_expected' => $expected,
        ];
    }
}

foreach (['contextFiles','truncateFiles','policyFiles','serialOffsetReparseFiles'] as $variable) {
    $$variable = array_values(array_unique($$variable));
    sort($$variable, SORT_NUMERIC);
}
$impacted = array_values(array_unique(array_merge(
    $contextFiles,
    $truncateFiles,
    $policyFiles,
    $serialOffsetReparseFiles
)));
sort($impacted, SORT_NUMERIC);

$result = [
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'staged_file_count' => count($rows),
    'referenced_name_rows_examined' => $referencedNameRows,
    'context_filter_file_count' => count($contextFiles),
    'runtime_name_truncation_file_count' => count($truncateFiles),
    'source_policy_refresh_file_count' => count($policyFiles),
    'serial_offset_pass1_reparse_file_count' => count($serialOffsetReparseFiles),
    'impacted_file_count' => count($impacted),
    'requires_original_package_read' => $serialOffsetReparseFiles !== [],
];
if (!isset($options['summary'])) {
    $result['context_filter_file_ids'] = $contextFiles;
    $result['runtime_name_truncation_file_ids'] = $truncateFiles;
    $result['source_policy_refresh_file_ids'] = $policyFiles;
    $result['serial_offset_pass1_reparse_file_ids'] = $serialOffsetReparseFiles;
    $result['impacted_file_ids'] = $impacted;
    $result['details'] = $details;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
