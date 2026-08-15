<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Health;

use PDO;
use UnrealDb\Catalog\Application\System\Contract\ReadinessProbe;

/** Verifies the durable queue table is accessible without scanning queue rows. */
final class PdoQueueReadinessProbe implements ReadinessProbe
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function name(): string
    {
        return 'job_queue';
    }

    public function ready(): bool
    {
        $statement = $this->db->query('SELECT id FROM ue_background_jobs WHERE 1=0');
        return $statement !== false;
    }
}
