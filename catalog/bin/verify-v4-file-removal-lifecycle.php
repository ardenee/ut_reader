#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$remove = (string)@file_get_contents(
    $root . '/src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php'
);
$action = (string)@file_get_contents(
    $root . '/src/Infrastructure/Maintenance/CatalogFileMaintenanceActionService.php'
);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'removal_uses_complete_projection_cleanup',
    str_contains($remove, '$support->deleteFileProjections($fileId);'),
    'normal verified-file removal must use centralized v4 projection cleanup'
);

$record(
    'removal_captures_alias_package_names',
    str_contains($remove, 'SELECT package_name FROM ue_file_package_aliases WHERE file_id=?')
        && str_contains($remove, '$packageNames[] = $aliasName;'),
    'removal must capture primary and alias logical package names before deleting the file'
);

$record(
    'removal_refreshes_alias_dependencies',
    str_contains($remove, 'foreach ($packageNames as $logicalPackageName)')
        && str_contains($remove, '$packageNames,')
        && str_contains($remove, 'CatalogProjectionReconciliationQueue::enqueue('),
    'affected dependencies and reconciliation must include alias package names'
);

$record(
    'alias_cleanup_owned_by_removal_service',
    str_contains($remove, 'DELETE FROM ue_file_package_aliases WHERE file_id=?')
        && !str_contains($action, 'DELETE FROM ue_file_package_aliases WHERE file_id=?'),
    'alias deletion must occur inside the same removal workflow rather than afterward'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
