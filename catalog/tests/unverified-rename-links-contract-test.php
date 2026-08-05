<?php
declare(strict_types=1);

function unverified_rename_links_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$list = file_get_contents(__DIR__ . '/../unverified-files.php');
$details = file_get_contents(__DIR__ . '/../unverified-file-details.php');
$action = file_get_contents(__DIR__ . '/../unverified-file-rename.php');
$rename = file_get_contents(__DIR__ . '/../lib/CatalogUnverifiedRename.php');

foreach (['list' => $list, 'details' => $details, 'action' => $action, 'rename helper' => $rename] as $name => $source) {
    unverified_rename_links_expect(is_string($source) && $source !== '', 'Could not read ' . $name . '.');
}

unverified_rename_links_expect(!str_contains($list, 'unverified-file-info.php'), 'The removed 404 route is still referenced.');
unverified_rename_links_expect(!str_contains($list, 'Review details'), 'The per-row Review details button must remain removed.');
unverified_rename_links_expect(str_contains($list, 'Possible games'), 'The list must contain the Possible games column.');
unverified_rename_links_expect(str_contains($list, 'uvf_reference_matches('), 'The list must use the bounded package-reference matcher.');
unverified_rename_links_expect(str_contains($list, 'possible package link'), 'Possible game rows must show their link count.');
unverified_rename_links_expect(str_contains($details, 'action="unverified-file-rename.php"'), 'Details must expose the rename form.');
unverified_rename_links_expect(str_contains($details, "catalog_csrf('unverified-file-rename')"), 'Rename form must use a dedicated CSRF token.');
unverified_rename_links_expect(str_contains($action, "catalog_check_csrf('unverified-file-rename')"), 'Rename action must verify the CSRF token.');
unverified_rename_links_expect(str_contains($rename, "preg_match('/(\\.uz(?:2|3)?)$/i'"), 'Rename helper must preserve redirect wrapper suffixes.');
unverified_rename_links_expect(str_contains($rename, 'unverified_queue_key=?'), 'Rename helper must reject queue-key collisions.');
unverified_rename_links_expect(str_contains($rename, 'UPDATE ue_files SET package_name=?'), 'Rename helper must update package and filename metadata.');
unverified_rename_links_expect(str_contains($rename, 'UPDATE ue_exports SET full_path=?'), 'Package-root changes must update staged export paths.');

echo "Unverified rename and possible-link contract tests passed.\n";
