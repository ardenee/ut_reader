<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies file identity display behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function identity_display_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$ui = file_get_contents(__DIR__ . '/../src/Presentation/Ui/CatalogUi.php');
identity_display_expect(is_string($ui), 'Could not read CatalogUi.');
foreach ([
    'public static function identity(',
    '<strong>GUID</strong>',
    '<strong>MD5</strong>',
    '<strong>SHA</strong>',
    'white-space:nowrap!important',
    'catalog-identities.js',
] as $fragment) {
    identity_display_expect(str_contains($ui, $fragment), 'Canonical identity renderer is missing: ' . $fragment);
}
$guidPosition = strpos($ui, '<strong>GUID</strong>');
$md5Position = strpos($ui, '<strong>MD5</strong>');
$shaPosition = strpos($ui, '<strong>SHA</strong>');
identity_display_expect(
    $guidPosition !== false && $md5Position !== false && $shaPosition !== false
        && $guidPosition < $md5Position && $md5Position < $shaPosition,
    'Identity renderer does not use GUID, MD5, SHA order.'
);

$hooks = file_get_contents(__DIR__ . '/../src/Presentation/Http/LegacySupportHooks.php');
identity_display_expect(is_string($hooks), 'Could not read legacy presentation hooks.');
foreach ([
    'registerIdentityAssets',
    'catalog-identities.js',
    "str_contains(\$requestPath, '/catalog/federation/')",
    "str_contains(\$output, 'catalog-identities.js')",
] as $fragment) {
    identity_display_expect(str_contains($hooks, $fragment), 'Global identity asset loading is missing: ' . $fragment);
}

$endpoint = file_get_contents(__DIR__ . '/../api/v1/file-identities.php');
identity_display_expect(is_string($endpoint), 'Could not read file identity endpoint.');
foreach ([
    'SELECT id,package_guid,md5,sha1 FROM ue_files',
    "'guid' =>",
    "'md5' =>",
    "'sha' =>",
    'No more than 500 file identities',
] as $fragment) {
    identity_display_expect(str_contains($endpoint, $fragment), 'File identity endpoint is missing: ' . $fragment);
}

$client = file_get_contents(__DIR__ . '/../assets/catalog-identities.js');
identity_display_expect(is_string($client), 'Could not read identity normalization client.');
foreach ([
    'window.__unrealDbCatalogIdentitiesLoaded',
    "strong.textContent = label",
    "identityLine('GUID'",
    "identityLine('MD5'",
    "identityLine('SHA'",
    "primary.header.textContent = 'Identity'",
    'column.header.hidden = true',
    'whiteSpace = \'nowrap\'',
    'file-identities.php',
    'MutationObserver',
] as $fragment) {
    identity_display_expect(str_contains($client, $fragment), 'Identity normalization client is missing: ' . $fragment);
}

$search = file_get_contents(__DIR__ . '/../index.php');
identity_display_expect(is_string($search), 'Could not read catalog search page.');
identity_display_expect(str_contains($search, '<th>Identity</th>'), 'Search results do not use the canonical Identity column.');
identity_display_expect(str_contains($search, 'CatalogUi::identity('), 'Search results do not render GUID/MD5/SHA server-side.');
identity_display_expect(!str_contains($search, '<th>GUID / MD5</th>'), 'Search results still use the old GUID / MD5 heading.');

$pakInfo = file_get_contents(__DIR__ . '/../pak-info.php');
identity_display_expect(is_string($pakInfo), 'Could not read PAK details.');
identity_display_expect(
    substr_count($pakInfo, 'CatalogUi::identity(') >= 2,
    'PAK archive and entry identities are not rendered canonically.'
);
identity_display_expect(str_contains($pakInfo, 'f.sha1 file_sha1'), 'PAK entry identities do not include the file SHA.');

$upkInfo = file_get_contents(__DIR__ . '/../upk-info.php');
identity_display_expect(is_string($upkInfo), 'Could not read UPK details.');
identity_display_expect(str_contains($upkInfo, 'CatalogUi::identity('), 'UPK details do not render GUID/MD5/SHA canonically.');
identity_display_expect(!str_contains($upkInfo, '<tr><th>MD5</th>'), 'UPK details still render a separate MD5 row.');
identity_display_expect(!str_contains($upkInfo, '<tr><th>SHA1</th>'), 'UPK details still render a separate SHA row.');

$gameList = file_get_contents(__DIR__ . '/../src/Application/Catalog/CatalogGameFileListService.php');
identity_display_expect(is_string($gameList), 'Could not read game file list service.');
identity_display_expect(
    substr_count($gameList, 'f.package_guid, f.md5, f.sha1') >= 2,
    'Game file list queries do not include GUID, MD5 and SHA for every sort path.'
);

echo "Canonical file identity display contract tests passed.\n";
