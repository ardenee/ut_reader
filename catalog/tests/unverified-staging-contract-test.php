<?php
declare(strict_types=1);

function staging_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$bucketController = file_get_contents(__DIR__ . '/../upload-bucket.php');
staging_contract_expect(is_string($bucketController), 'upload-bucket.php could not be read.');
staging_contract_expect(
    str_contains($bucketController, 'LegacyUnverifiedFileStager'),
    'Upload Bucket is not composed with the explicit unverified stager.'
);
staging_contract_expect(
    str_contains($bucketController, 'stageBucketUpload('),
    'Upload Bucket does not stage the stored file during the request.'
);
staging_contract_expect(
    str_contains($bucketController, "data.append('relative_path', shownName)"),
    'Upload Bucket no longer preserves folder-relative identity context.'
);

$compatibilityHook = file_get_contents(__DIR__ . '/../lib/CatalogUnverifiedAutoIndex.php');
staging_contract_expect(is_string($compatibilityHook), 'CatalogUnverifiedAutoIndex.php could not be read.');
staging_contract_expect(
    !str_contains($compatibilityHook, "'upload-bucket.php'"),
    'Upload Bucket regressed to shutdown-time directory indexing.'
);
staging_contract_expect(
    !str_contains($compatibilityHook, "'upload-to-parent.php'"),
    'A non-queue federation page is still registered for directory indexing.'
);
staging_contract_expect(
    str_contains($compatibilityHook, "'profiled-upload.php'")
        && str_contains($compatibilityHook, "'http-source-scan.php'"),
    'The temporary compatibility hook no longer documents its remaining writers.'
);

$stager = file_get_contents(__DIR__ . '/../src/Infrastructure/Legacy/LegacyUnverifiedFileStager.php');
staging_contract_expect(is_string($stager), 'LegacyUnverifiedFileStager.php could not be read.');
staging_contract_expect(
    str_contains($stager, 'catalog_unverified_index_path('),
    'The explicit staging service no longer creates the database row.'
);
staging_contract_expect(
    str_contains($stager, 'Database staging failed:'),
    'The explicit staging service no longer records recoverable database failures beside retained files.'
);

echo "Explicit unverified staging contract tests passed.\n";
