#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;

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

$game = $db->query("SELECT id FROM ue_games WHERE slug='unrealgold' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "Unreal / Unreal Gold game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];
$reader = new Uedb5MetadataReader($storage);

$statement = $db->prepare(
    'SELECT file_id,source_policy FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id'
);
$statement->execute([$gameId]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$policyFiles = [];
$pre50ReparseFiles = [];
$generationReparseFiles = [];
$missingGenerationFiles = [];
$details = [];

$expectedPolicy = static function (int $version): string {
    if ($version > 69) {
        return Uedb5UnrealSnapshotBuilder::POLICY_POST_V69_UNRESOLVED;
    }
    if ($version < 60) {
        return Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY;
    }
    if ($version <= 68) {
        return Uedb5UnrealSnapshotBuilder::POLICY_V224_PARTIAL;
    }
    return Uedb5UnrealSnapshotBuilder::POLICY_V227_PARTIAL;
};

foreach ($rows as $registration) {
    $fileId = (int)$registration['file_id'];
    $summary = $reader->page($gameId, $fileId, 'summary', 0, 1)[0] ?? null;
    if (!is_array($summary)) {
        throw new RuntimeException("UEDB5 summary is missing for Unreal file #$fileId.");
    }

    $version = (int)($summary['package_version'] ?? -1);
    $actualPolicy = (string)($registration['source_policy'] ?? '');
    $expected = $expectedPolicy($version);
    $policyChanged = $actualPolicy !== $expected;
    if ($policyChanged) {
        $policyFiles[] = $fileId;
    }

    $pre50 = $version >= 0 && $version < 50;
    if ($pre50) {
        $pre50ReparseFiles[] = $fileId;
    }

    $generationOutOfRange = false;
    $generationMissing = false;
    $generationCount = null;
    if ($version >= 68) {
        if (!array_key_exists('generation_count', $summary) || $summary['generation_count'] === null) {
            $generationMissing = true;
            $missingGenerationFiles[] = $fileId;
            $generationReparseFiles[] = $fileId;
        } else {
            $generationCount = (int)$summary['generation_count'];
            if ($generationCount < 0 || $generationCount > 64) {
                $generationOutOfRange = true;
                $generationReparseFiles[] = $fileId;
            }
        }
    }

    if ($policyChanged || $pre50 || $generationOutOfRange || $generationMissing) {
        $details[(string)$fileId] = [
            'package_version' => $version,
            'generation_count' => $generationCount,
            'generation_count_missing' => $generationMissing,
            'generation_count_out_of_range' => $generationOutOfRange,
            'pre50_export_layout_requires_pass1_reparse' => $pre50,
            'source_policy_current' => $actualPolicy,
            'source_policy_expected' => $expected,
        ];
    }
}

foreach (['policyFiles','pre50ReparseFiles','generationReparseFiles','missingGenerationFiles'] as $variable) {
    $$variable = array_values(array_unique($$variable));
    sort($$variable, SORT_NUMERIC);
}
$pass1Files = array_values(array_unique(array_merge($pre50ReparseFiles, $generationReparseFiles)));
sort($pass1Files, SORT_NUMERIC);
$impacted = array_values(array_unique(array_merge($policyFiles, $pass1Files)));
sort($impacted, SORT_NUMERIC);

$result = [
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'staged_file_count' => count($rows),
    'source_policy_refresh_file_count' => count($policyFiles),
    'pre50_pass1_reparse_file_count' => count($pre50ReparseFiles),
    'generation_count_pass1_reparse_file_count' => count($generationReparseFiles),
    'missing_generation_count_file_count' => count($missingGenerationFiles),
    'pass1_reparse_file_count' => count($pass1Files),
    'impacted_file_count' => count($impacted),
    'requires_original_package_read' => $pass1Files !== [],
];
if (!isset($options['summary'])) {
    $result['source_policy_refresh_file_ids'] = $policyFiles;
    $result['pre50_pass1_reparse_file_ids'] = $pre50ReparseFiles;
    $result['generation_count_pass1_reparse_file_ids'] = $generationReparseFiles;
    $result['missing_generation_count_file_ids'] = $missingGenerationFiles;
    $result['pass1_reparse_file_ids'] = $pass1Files;
    $result['impacted_file_ids'] = $impacted;
    $result['details'] = $details;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
