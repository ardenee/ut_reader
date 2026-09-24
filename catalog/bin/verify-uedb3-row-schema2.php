#!/usr/bin/env php
<?php
/**
 * Static contract for .uedb3 row schema 2 and indexed engine identity projections.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static fn(string $path): string => (string)@file_get_contents($root . '/' . $path);

$container = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php');
$reader = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php');
$builder = $read('src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php');
$enricher = $read('src/Infrastructure/Metadata/CatalogCompactIdentityEnricher.php');
$hash = $read('src/Infrastructure/Metadata/CatalogUnrealIdentityHash.php');
$writer = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$resolver = $read('src/Infrastructure/Persistence/PdoLegacyVerifyImportProjectionResolver.php');
$coverage = $read('src/Infrastructure/Persistence/PdoPackageObjectCoverageResolver.php');
$upgrade = $read('bin/upgrade-uedb3-row-schema.php');

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$check(
    'row_schema_version_is_explicit',
    str_contains($container, "'row_schema_version' => 2")
        && str_contains($reader, "row_schema_version")
        && str_contains($reader, "?? 1"),
    'New .uedb3 files must identify row schema 2 while old files remain readable as schema 1.'
);

$check(
    'hash_algorithms_are_versioned',
    str_contains($hash, "VERIFY_IMPORT_ALGORITHM")
        && str_contains($hash, "OBJECT_PATH_ALGORITHM")
        && str_contains($container, "identity_hash_algorithm")
        && str_contains($container, "path_hash_algorithm"),
    'The manifest must record stable names for both derived hash contracts.'
);

$check(
    'imports_persist_identity_and_path_hashes',
    str_contains($container, "verify_identity_hash")
        && str_contains($container, "path_hash_ci")
        && str_contains($reader, "'verify_identity_hash'")
        && str_contains($reader, "'path_hash_ci'"),
    'Import rows must carry the durable VerifyImport identity hash and normalized path hash.'
);

$check(
    'exports_persist_exact_verify_identity',
    str_contains($container, "verify_class_package")
        && str_contains($container, "verify_class_name")
        && str_contains($container, "verify_identity_hash")
        && str_contains($reader, "'verify_class_package'")
        && str_contains($reader, "'verify_class_name'"),
    'Export rows must retain the exact class package/name used to derive VerifyImport identity.'
);

$check(
    'identity_enrichment_is_generation_aware',
    str_contains($enricher, "['UE1', 'UE2']")
        && str_contains($enricher, "verify_identity_hash")
        && str_contains($enricher, "path_hash_ci")
        && str_contains($builder, "CatalogCompactIdentityEnricher::enrich"),
    'UE2 VerifyImport identity is derived only for UE1/UE2, while normalized path hashes are available generally.'
);

$check(
    'sql_projection_contains_verify_identity',
    str_contains($writer, "ue_legacy_export_identity_lookup")
        && str_contains($writer, "verify_identity_hash")
        && str_contains($writer, "path_hash_ci"),
    'Publication must project durable engine identity and path hashes into indexed SQL.'
);

$check(
    'consumer_projection_contains_verify_identity',
    str_contains($writer, "'verify_identity_hash'")
        && str_contains($writer, "'required_path_hash_ci'")
        && str_contains($writer, "ue_dependency_links"),
    'Consumer dependency rows must retain the same indexed identity/path keys.'
);

$check(
    'verify_import_runtime_is_projection_only',
    str_contains($resolver, "ue_legacy_export_identity_lookup")
        && str_contains($resolver, "ue_terms")
        && !str_contains($resolver, "BlockedCompressedMetadataReader"),
    'Normal UE1/UE2 VerifyImport resolution must not reopen provider .uedb3 files.'
);

$check(
    'hash_hits_are_exactly_confirmed',
    str_contains($resolver, "object_name")
        && str_contains($resolver, "class_name")
        && str_contains($resolver, "class_package")
        && str_contains($resolver, "CatalogUnrealIdentityHash::nameKey"),
    'A hash is only an accelerator; raw projected names must confirm every candidate.'
);

$check(
    'coverage_uses_normalized_path_projection',
    str_contains($coverage, "path_hash_ci")
        && str_contains($coverage, "local_path")
        && !str_contains($coverage, "BlockedCompressedMetadataReader")
        && !str_contains($coverage, "PdoCompactCaseInsensitiveExportResolver"),
    'Package coverage/case-insensitive path matching must also avoid metadata-file fallback scans.'
);

$check(
    'schema_upgrade_does_not_reparse_packages',
    str_contains($upgrade, "BlockedCompressedMetadataSnapshotLoader")
        && str_contains($upgrade, "CatalogCompactIdentityEnricher")
        && str_contains($upgrade, "BlockedCompressedMetadataSnapshotWriter")
        && !str_contains($upgrade, "CatalogLegacyPackageReader")
        && !str_contains($upgrade, "UnrealPackageReader"),
    'Existing .uedb3 files must upgrade from their own authoritative metadata without reopening original UE packages.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
