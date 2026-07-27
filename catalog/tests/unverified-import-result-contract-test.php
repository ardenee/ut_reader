<?php
declare(strict_types=1);

function unverified_import_result_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$action = file_get_contents(__DIR__ . '/../unverified-files-action.php');
unverified_import_result_expect(is_string($action), 'unverified-files-action.php could not be read.');
unverified_import_result_expect(
    str_contains($action, 'SELECT package_guid,name_count,import_count,export_count FROM ue_files WHERE id=?'),
    'Unverified import results do not load the verified package identity and table counts.'
);
unverified_import_result_expect(
    str_contains($action, "'name_count' =>")
        && str_contains($action, "'import_count' =>")
        && str_contains($action, "'export_count' =>")
        && str_contains($action, "'package_guid' =>"),
    'Unverified import results do not return structured package details.'
);
unverified_import_result_expect(
    str_contains($action, "'. N/I/E: '") && str_contains($action, "' | GUID: '"),
    'Unverified import progress does not display N/I/E and GUID.'
);
unverified_import_result_expect(
    str_contains($action, "'verified' => 'Imported'")
        && !str_contains($action, "'verified' => 'Verified'"),
    'Successful unverified imports are not labelled as Imported.'
);
unverified_import_result_expect(
    !str_contains($action, "trim((string)\$result['message'])"),
    'Unverified import progress still prioritizes the internal promotion implementation message.'
);

$page = file_get_contents(__DIR__ . '/../unverified-files.php');
unverified_import_result_expect(is_string($page), 'unverified-files.php could not be read.');
unverified_import_result_expect(
    str_contains($page, "min(1000, uv_page_int('limit', 100))"),
    'Unverified Files still caps the requested page size below 1000 rows.'
);
unverified_import_result_expect(
    str_contains($page, 'foreach ([50, 100, 250, 500, 1000] as $value)'),
    'Unverified Files does not offer the same 50–1000 row choices as Background Jobs.'
);

echo "Unverified import result contract tests passed.\n";
