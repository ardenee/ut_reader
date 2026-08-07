<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies scanner bulk insert behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function scanner_bulk_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scanner = file_get_contents(__DIR__ . '/../lib/CatalogScanner.php');
scanner_bulk_expect(is_string($scanner), 'CatalogScanner.php could not be read.');
scanner_bulk_expect(
    str_contains($scanner, 'function scanner_bulk_insert(')
        && str_contains($scanner, "count(\$batch) >= 250"),
    'Scanner does not provide bounded multi-row database batches.'
);
foreach (['ue_names', 'ue_imports', 'ue_exports', 'ue_dependencies'] as $table) {
    scanner_bulk_expect(
        str_contains($scanner, "scanner_bulk_insert(\$db, '" . $table . "'")
            || str_contains($scanner, "'" . $table . "',\n"),
        'Scanner does not batch writes for ' . $table . '.'
    );
}
scanner_bulk_expect(
    str_contains($scanner, 'SELECT id,root_package,full_path,relative_object_path,is_common FROM ue_imports')
        && !str_contains($scanner, 'SELECT * FROM ue_imports WHERE file_id=?'),
    'Dependency rebuild still loads wide import rows.'
);
scanner_bulk_expect(
    str_contains($scanner, 'WHERE game_id=? AND scan_status="verified" ORDER BY package_name, id'),
    'Full game dependency rebuild is not restricted to verified files.'
);

fwrite(STDOUT, "Scanner bulk insert contract tests passed.\n");
