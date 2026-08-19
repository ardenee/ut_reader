#!/usr/bin/env php
<?php
/**
 * Repairs queued archive-member children created on a different queue from
 * their archive parent. Dry-run by default; pass --execute to update rows.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    $execute = in_array('--execute', $argv, true);
    $application = catalog_bootstrap(false);
    $db = $application->db;

    $where = <<<'SQL'
FROM ue_background_jobs c
JOIN ue_background_jobs p ON p.id=c.parent_job_id
WHERE c.status='queued'
  AND c.queue_name<>p.queue_name
  AND c.workflow_unit_key LIKE 'archive:%'
  AND c.job_type IN (
      'catalog.process_bucket_staged_package',
      'catalog.import_staged_package',
      'catalog.import_staged_pak'
  )
  AND p.job_type IN (
      'catalog.process_bucket_archive',
      'catalog.import_staged_archive'
  )
SQL;

    $count = $db->query('SELECT COUNT(*) ' . $where);
    $matching = max(0, (int)$count->fetchColumn());

    $sampleStatement = $db->query(
        'SELECT c.id child_job_id,c.parent_job_id,c.queue_name source_queue,p.queue_name target_queue,'
        . 'c.job_type,c.workflow_unit_key '
        . $where
        . ' ORDER BY c.id ASC LIMIT 50'
    );
    $sample = $sampleStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $updated = 0;
    $conflicts = 0;
    if ($execute && $matching > 0) {
        $db->beginTransaction();
        try {
            // A duplicate target-row should be extremely unusual, but never
            // violate an existing queue/dedupe identity while repairing history.
            $conflictStatement = $db->query(
                'SELECT COUNT(*) ' . $where
                . ' AND c.dedupe_key IS NOT NULL AND EXISTS('
                . 'SELECT 1 FROM ue_background_jobs x '
                . 'WHERE x.id<>c.id AND x.queue_name=p.queue_name AND x.dedupe_key=c.dedupe_key LIMIT 1)'
            );
            $conflicts = max(0, (int)$conflictStatement->fetchColumn());

            $update = $db->prepare(
                'UPDATE ue_background_jobs c '
                . 'JOIN ue_background_jobs p ON p.id=c.parent_job_id '
                . 'LEFT JOIN ue_background_jobs x '
                . 'ON x.id<>c.id AND x.queue_name=p.queue_name AND x.dedupe_key=c.dedupe_key '
                . 'SET c.queue_name=p.queue_name '
                . 'WHERE c.status="queued" '
                . 'AND c.queue_name<>p.queue_name '
                . 'AND c.workflow_unit_key LIKE "archive:%" '
                . 'AND c.job_type IN ('
                . '"catalog.process_bucket_staged_package",'
                . '"catalog.import_staged_package",'
                . '"catalog.import_staged_pak") '
                . 'AND p.job_type IN ('
                . '"catalog.process_bucket_archive",'
                . '"catalog.import_staged_archive") '
                . 'AND (c.dedupe_key IS NULL OR x.id IS NULL)'
            );
            $update->execute();
            $updated = max(0, $update->rowCount());
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    $result = [
        'ok' => true,
        'mode' => $execute ? 'execute' : 'dry_run',
        'matching_queued_children' => $matching,
        'updated_children' => $updated,
        'dedupe_conflicts' => $conflicts,
        'sample' => $sample,
        'note' => $execute
            ? 'Only still-queued archive children were moved to their parent queue; running/terminal jobs were untouched.'
            : 'Read-only. Re-run with --execute to move still-queued archive children to their parent queue.',
    ];
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => get_class($error) . ': ' . $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
