<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies job storage cleanup behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function job_storage_cleanup_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$cleanup = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobStorageCleanup.php');
$wrapper = file_get_contents($root . '/src/Infrastructure/Jobs/GameBackupImportCleanupJobHandler.php');
$importer = file_get_contents($root . '/src/Infrastructure/Jobs/GameBackupImportJobHandler.php');
$factory = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$maintenance = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php');
$cli = file_get_contents($root . '/bin/cleanup-job-storage.php');

foreach (compact('cleanup', 'wrapper', 'importer', 'factory', 'maintenance', 'cli') as $name => $source) {
    job_storage_cleanup_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

job_storage_cleanup_expect(
    str_contains($cleanup, 'jobs' . DIRECTORY_SEPARATOR . 'incoming')
        && str_contains($cleanup, 'jobs' . DIRECTORY_SEPARATOR . 'game-backup-import')
        && str_contains($cleanup, 'payload_json LIKE "%jobs/incoming/%"')
        && str_contains($cleanup, 'collectIncomingReferences')
        && str_contains($cleanup, 'status IN ("queued","running")')
        && str_contains($cleanup, 'IMPORT_GAME_BACKUP')
        && str_contains($cleanup, "preg_match('/^restore-([0-9]+)-/i'"),
    'Job-storage cleanup does not preserve surviving job references and active backup restores.'
);

job_storage_cleanup_expect(
    str_contains($cleanup, "if ((int)\$entry->getMTime() > \$threshold)")
        && str_contains($cleanup, "\$result['referenced']++")
        && str_contains($cleanup, "\$result['active']++")
        && str_contains($cleanup, '@unlink('),
    'Job-storage cleanup does not apply the safety window or report protected/deleted files.'
);

job_storage_cleanup_expect(
    !str_contains($importer, "                \$temporary = '';\n\n                \$status")
        && str_contains($importer, "finally {\n                if (\$temporary !== '' && is_file(\$temporary))"),
    'Backup restore still discards the working-copy path before per-file cleanup.'
);

job_storage_cleanup_expect(
    str_contains($wrapper, 'finally')
        && str_contains($wrapper, 'removeJobWorkingCopies')
        && str_contains($factory, 'new GameBackupImportCleanupJobHandler(')
        && str_contains($factory, 'new GameBackupImportJobHandler('),
    'Backup import jobs are not protected by final whole-job working-copy cleanup.'
);

job_storage_cleanup_expect(
    str_contains($maintenance, 'new CatalogJobStorageCleanup(')
        && str_contains($maintenance, "'job_storage' => \$jobStorage")
        && str_contains($cli, 'CatalogJobStorageCleanup')
        && str_contains($cli, '--min-age-seconds='),
    'Reference-aware cleanup is not available through maintenance and the CLI.'
);

fwrite(STDOUT, "Job storage cleanup contract tests passed.\n");
