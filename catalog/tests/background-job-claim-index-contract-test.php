<?php
declare(strict_types=1);

function claim_index_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migrationPath = __DIR__ . '/../migrations/202607310006_background_job_claim_index.php';
$migration = file_get_contents($migrationPath);
claim_index_expect(is_string($migration) && $migration !== '', 'Background-job claim-index migration is missing.');

claim_index_expect(
    str_contains($migration, "'version' => '202607310006'")
        && str_contains(
            $migration,
            '(queue_name,status,cancel_requested_at,priority,available_at,id)'
        )
        && str_contains($migration, "\$current !== \$wanted")
        && str_contains($migration, 'ANALYZE TABLE ue_background_jobs'),
    'The claim index does not match the queue filters and priority ordering or is not idempotent.'
);

claim_index_expect(
    substr_count($migration, 'fetchAll(PDO::FETCH_ASSOC)') === 2
        && substr_count($migration, 'closeCursor()') >= 3,
    'The migration leaves an ANALYZE or metadata result set unread.'
);

echo "Background job claim-index contract tests passed.\n";
