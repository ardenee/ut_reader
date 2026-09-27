#!/usr/bin/env php
<?php
/**
 * Read-only UE4 residual missing-dependency audit using the same deterministic
 * VerifyImport resolver as production dependency rebuilding.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

$options = getopt('', ['game-id::', 'examples::', 'progress-every::']);
$gameId = max(1, (int)($options['game-id'] ?? 7));
$exampleLimit = max(0, min(50, (int)($options['examples'] ?? 10)));
$progressEvery = max(1, (int)($options['progress-every'] ?? 100));

$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') throw new RuntimeException('catalog storage_path is required.');
$loader = new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot);

$game = catalog_one(
    $db,
    'SELECT g.id,g.name,g.slug,p.engine_key,p.profile_name FROM ue_games g '
    . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=? LIMIT 1',
    [$gameId]
);
if (!$game || strtoupper(trim((string)($game['engine_key'] ?? ''))) !== 'UE4') {
    throw new RuntimeException('Selected game is not an active UE4 game.');
}

$groups = catalog_all(
    $db,
    'SELECT DISTINCT l.file_id,CONVERT(pkg.value_prefix USING utf8mb4) required_package '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'AND CONVERT(pkg.value_prefix USING utf8mb4) NOT LIKE "/Script/%" '
    . 'ORDER BY l.file_id,required_package',
    [BlockedCompressedMetadataContainer::FORMAT_VERSION, $gameId]
);

$counts = [
    'no_package_provider' => 0,
    'provider_rejected_by_ue4_verifyimport' => 0,
    'v4_package_context_unavailable' => 0,
    'suspicious_complete_provider_still_missing' => 0,
];
$examples = [];
$snapshotCache = [];
$processed = 0;

foreach ($groups as $group) {
    $fileId = (int)($group['file_id'] ?? 0);
    $packageName = trim((string)($group['required_package'] ?? ''));
    if ($fileId < 1 || $packageName === '') continue;

    if (!isset($snapshotCache[$fileId])) {
        $snapshotCache = [$fileId => $loader->loadDependencySnapshot($fileId)];
    }
    $imports = array_values((array)($snapshotCache[$fileId]['imports'] ?? []));
    $required = [];
    $needsV5Context = false;
    foreach ($imports as $fallback => $import) {
        if (!is_array($import) || strcasecmp(trim((string)($import['root_package'] ?? '')), $packageName) !== 0) continue;
        if (trim((string)($import['relative_object_path'] ?? '')) === '') continue;
        $index = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
        $required[] = $index;
        if ((int)($import['outer_index'] ?? 0) > 0) $needsV5Context = true;
    }
    if ($required === []) continue;

    $providerRows = catalog_all(
        $db,
        'SELECT f.id file_id FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE f.game_id=? AND f.scan_status="verified" AND f.package_name=? '
        . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
        . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
        . 'UNION SELECT a.file_id FROM ue_file_package_aliases a '
        . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE a.game_id=? AND f.scan_status="verified" AND a.package_name=? '
        . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
        . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1))',
        [BlockedCompressedMetadataContainer::FORMAT_VERSION, $gameId, $packageName,
         BlockedCompressedMetadataContainer::FORMAT_VERSION, $gameId, $packageName]
    );
    $providerIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int)($row['file_id'] ?? 0),
        $providerRows
    )));
    $providerIds = array_values(array_filter($providerIds, static fn(int $id): bool => $id > 0));

    $providerResults = [];
    $hasComplete = false;
    foreach ($providerIds as $providerId) {
        $matches = PdoUe4VerifyImportProjectionResolver::resolveProvider($db, $providerId, $imports);
        $matched = 0;
        foreach ($required as $index) {
            if (array_key_exists($index, $matches)) $matched++;
        }
        $providerResults[] = [
            'file_id' => $providerId,
            'matched' => $matched,
            'required' => count($required),
        ];
        if ($matched === count($required)) {
            $hasComplete = true;
            break;
        }
    }

    if ($providerIds === []) {
        $classification = 'no_package_provider';
    } elseif ($hasComplete) {
        $classification = 'suspicious_complete_provider_still_missing';
    } elseif ($needsV5Context) {
        $classification = 'v4_package_context_unavailable';
    } else {
        $classification = 'provider_rejected_by_ue4_verifyimport';
    }
    $counts[$classification]++;

    if ($exampleLimit > 0 && count($examples[$classification] ?? []) < $exampleLimit) {
        $owner = catalog_one($db, 'SELECT original_name,package_version FROM ue_files WHERE id=? LIMIT 1', [$fileId]) ?: [];
        $examples[$classification][] = [
            'consumer_file_id' => $fileId,
            'consumer_file' => (string)($owner['original_name'] ?? ''),
            'package_version' => (int)($owner['package_version'] ?? 0),
            'required_package' => $packageName,
            'required_import_indexes' => $required,
            'providers' => $providerResults,
        ];
    }

    $processed++;
    if (($processed % $progressEvery) === 0) {
        fwrite(STDERR, 'Classified ' . $processed . '/' . count($groups) . PHP_EOL);
    }
}

arsort($counts);
echo json_encode([
    'ok' => true,
    'read_only' => true,
    'game' => $game,
    'metadata_format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
    'residual_non_script_groups' => count($groups),
    'classifications' => $counts,
    'examples_per_classification' => $examples,
    'interpretation' => [
        'suspicious_complete_provider_still_missing' => 'Investigate first: production UE4 VerifyImport semantics found one complete physical provider although the persisted group is still missing.',
        'provider_rejected_by_ue4_verifyimport' => 'A physical package exists, but no one provider satisfies the complete object/class/class-package/outer/public Import set.',
        'no_package_provider' => 'No verified game-local physical provider exists for the serialized package identity.',
        'v4_package_context_unavailable' => 'The consumer graph needs modern UE4 package context that v4 does not preserve; v5 must retain FObjectImport::PackageName before resolving it.',
    ],
    'invariant' => 'Consumer Imports create requirements; one physical provider must independently satisfy the complete set.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
