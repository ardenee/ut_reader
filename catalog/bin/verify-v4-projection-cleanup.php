#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$support = (string)@file_get_contents(
    $root . '/src/Infrastructure/Maintenance/CatalogFileMaintenanceSupport.php'
);
$demotion = (string)@file_get_contents(
    $root . '/src/Infrastructure/Games/CatalogVerifiedFileDemotionService.php'
);
$reassignment = (string)@file_get_contents(
    $root . '/src/Infrastructure/Games/CatalogVerifiedFileReassignmentService.php'
);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

foreach ([
    'ue_name_lookup',
    'ue_export_lookup',
    'ue_export_path_lookup',
    'ue_legacy_export_identity_lookup',
    'ue_dependency_links',
    'ue_dependency_identity_lookup',
] as $table) {
    $record(
        'projection_cleanup:' . $table,
        str_contains($support, "'" . $table . "'"),
        'current projection cleanup must include ' . $table
    );
}

$record(
    'demotion_uses_shared_cleanup',
    str_contains($demotion, '$support->deleteFileProjections($fileId);'),
    'verified-to-unverified demotion must use shared projection cleanup'
);

$record(
    'reassignment_uses_shared_cleanup',
    str_contains($reassignment, '$support->deleteFileProjections($fileId);'),
    'verified source retirement must use shared projection cleanup'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
