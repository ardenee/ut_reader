#!/usr/bin/env php
<?php
/**
 * Bounded production post-cutover check: no package scans, no writes.
 * Registration/status coverage is not the same as exhaustive hash/source parity.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5CutoverReadinessVerifier;

try {
    $app = catalog_bootstrap(false);
    $db = $app->db;
    $count = static fn(string $sql): int => (int)$db->query($sql)->fetchColumn();
    $checks = [];
    $check = static function(string $name, bool $ok, mixed $detail) use (&$checks): void {
        $checks[] = ['check'=>$name,'ok'=>$ok,'detail'=>$detail];
    };
    $verified = $count('SELECT COUNT(*) FROM ue_files WHERE scan_status="verified"');
    $v5 = $count('SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id AND v.format_version=5 WHERE f.scan_status="verified"');
    $validated = $count('SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_migration_status s ON s.file_id=f.id AND s.status="validated" WHERE f.scan_status="verified"');
    $invalid = $count('SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id WHERE f.scan_status<>"verified"');
    $legacy = $count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ue_file_metadata','ue_names','ue_imports','ue_exports','ue_dependencies','ue_search_documents','ue_terms','ue_name_lookup','ue_export_lookup','ue_export_path_lookup','ue_legacy_export_identity_lookup','ue_dependency_links','ue_dependency_identity_lookup')");
    $source = Uedb5CutoverReadinessVerifier::sourceChecks(dirname(__DIR__));
    $check('verified_files_have_v5', $verified > 0 && $verified === $v5, ['verified'=>$verified,'v5'=>$v5]);
    $check('verified_files_marked_validated', $verified === $validated, ['verified'=>$verified,'validated'=>$validated]);
    $check('v5_excludes_nonverified', $invalid === 0, $invalid);
    $check('legacy_sql_tables_absent', $legacy === 0, $legacy);
    $check('v5_source_has_no_v4_fallback', (bool)($source['checks'] ?? false) && ($source['failures'] ?? []) === [], $source['failures'] ?? []);
    $ok = !in_array(false, array_column($checks, 'ok'), true);
    echo json_encode(['ok'=>$ok,'scope'=>'bounded post-cutover SQL/source contract; excludes physical .uedb4 inventory, exhaustive hashes and source parity','checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($ok?0:2);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
