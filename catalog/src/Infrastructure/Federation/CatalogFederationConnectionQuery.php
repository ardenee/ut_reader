<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides bounded administrator reads used by the Federation Connections page.
 * Why: Rendering code should not embed federation persistence SQL.
 * Role: Infrastructure query model for incoming join requests and transition guards.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;

final class CatalogFederationConnectionQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function incomingJoinRequests(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $statement = $this->db->query(
            'SELECT * FROM ue_federation_join_requests '
            . 'ORDER BY FIELD(status,"pending","approved","claimed","denied","expired"), '
            . 'created_at DESC, id DESC LIMIT ' . $limit
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
