#!/usr/bin/env php
<?php
/** Read-only source contract for staged v3 cutover and v2 cleanup. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static fn(string $p): string => (string)@file_get_contents($root . '/' . $p);
$cutover = $read('bin/v3-migration/cutover.php');
$cleanup = $read('bin/v3-migration/cleanup-v2.php');
$stage = $read('bin/v3-migration/stage.php');
$checks = [];
$check = static function(string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check'=>$name,'ok'=>$ok,'detail'=>$detail];
};
$check(
    'staging_does_not_mutate_runtime_registration',
    !preg_match('/UPDATE\\s+ue_file_metadata/i', $stage),
    'Offline staging must create .uedb3 without switching production DB rows.'
);
$check(
    'cutover_verifies_every_verified_file',
    str_contains($cutover, 'WHERE f.scan_status="verified"\' . $whereWorker . \' ORDER BY f.id')
        && str_contains($cutover, 'MetadataContainerV3::verifyFile')
        && str_contains($cutover, '$checked === $verifiedCount'),
    'Cutover must verify complete v3 population before any registration switch.'
);
$check(
    'cutover_refuses_running_jobs',
    str_contains($cutover, 'status="running"')
        && str_contains($cutover, 'Cutover refused'),
    'Workers/jobs must be stopped before the registration switch.'
);
$check(
    'cutover_is_atomic',
    str_contains($cutover, '$db->beginTransaction()')
        && str_contains($cutover, '$db->commit()')
        && str_contains($cutover, '$db->rollBack()')
        && str_contains($cutover, 'm.format_version=3'),
    'All verified metadata registrations must switch to v3 in one database transaction.'
);
$check(
    'cleanup_requires_v3_and_confirmation',
    str_contains($cleanup, 'm.format_version<>3')
        && str_contains($cleanup, '--confirm-delete-v2')
        && str_contains($cleanup, 'MetadataContainerV3::verifyFile'),
    'A v2 file may be deleted only after DB cutover and positive verification of its v3 counterpart.'
);
$check(
    'cleanup_targets_only_uedb2',
    str_contains($cleanup, "preg_replace('/\\\\.uedb3$/', '.uedb2'")
        && str_contains($cleanup, '@unlink($v2)')
        && !str_contains($cleanup, '@unlink($v3)'),
    'Cleanup must never delete the active .uedb3 container.'
);
$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok'=>$ok,'checks'=>$checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
