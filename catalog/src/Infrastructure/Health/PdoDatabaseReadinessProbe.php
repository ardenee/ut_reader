<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Health;

use PDO;
use UnrealDb\Catalog\Application\System\Contract\ReadinessProbe;

final class PdoDatabaseReadinessProbe implements ReadinessProbe
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function ready(): bool
    {
        return (int)$this->db->query('SELECT 1')->fetchColumn() === 1;
    }
}
