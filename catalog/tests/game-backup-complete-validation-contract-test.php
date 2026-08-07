<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies game backup complete validation behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function complete_backup_validation_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/GameBackupImportJobHandler.php');
complete_backup_validation_expect(is_string($handler), 'Could not read complete game-backup import handler.');
complete_backup_validation_expect(
    str_contains($handler, 'return $jobType === JobType::IMPORT_GAME_BACKUP;'),
    'The complete validation handler does not exclusively claim game-backup imports.'
);
complete_backup_validation_expect(
    !str_contains($handler, 'MAX_IMPORT_ERRORS'),
    'Game-backup validation still has a maximum retained failure count.'
);
complete_backup_validation_expect(
    !preg_match('/count\s*\(\s*\$errors\s*\)\s*</', $handler),
    'Game-backup validation conditionally stops recording errors.'
);
complete_backup_validation_expect(
    str_contains($handler, '$errors[] = ['),
    'Game-backup validation does not retain each failed file.'
);
complete_backup_validation_expect(
    str_contains($handler, "'validated' => \$total")
    && str_contains($handler, "'validation_complete' => true")
    && str_contains($handler, "'errors_complete' => true")
    && str_contains($handler, "'errors_truncated' => false"),
    'The completed backup report does not explicitly guarantee complete validation and error retention.'
);
complete_backup_validation_expect(
    str_contains($handler, "(\$imported + \$duplicates + \$aliases + \$failed) !== \$total"),
    'Game-backup validation does not verify that every manifest entry was classified.'
);
complete_backup_validation_expect(
    str_contains($handler, "'index' => \$index + 1")
    && str_contains($handler, "'file' => \$originalName")
    && str_contains($handler, "'exported_relative_path' => \$relative")
    && str_contains($handler, "'source_relative_path' => \$sourceRelative")
    && str_contains($handler, "'error_type' => get_class(\$error)")
    && str_contains($handler, "'error' => \$this->shortError(\$error)"),
    'Complete validation failures do not retain their full file and error context.'
);

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
complete_backup_validation_expect(is_string($factory), 'Could not read worker factory.');
$completeImportPosition = strpos($factory, 'new GameBackupImportJobHandler(');
$legacyBackupPosition = strpos($factory, 'new GameBackupJobHandler(');
complete_backup_validation_expect(
    $completeImportPosition !== false
    && $legacyBackupPosition !== false
    && $completeImportPosition < $legacyBackupPosition,
    'The complete import handler is not registered before the legacy capped handler.'
);

$viewer = file_get_contents(__DIR__ . '/../assets/game-backup-results.js');
complete_backup_validation_expect(is_string($viewer), 'Could not read game-backup result viewer.');
complete_backup_validation_expect(
    str_contains($viewer, 'Copy failed filenames + errors')
    && str_contains($viewer, 'errors.forEach(function (entry)'),
    'The game-backup result viewer cannot expose every retained failure.'
);

echo "Complete game-backup validation contract tests passed.\n";
