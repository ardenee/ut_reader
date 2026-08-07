<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the pure query specification for federation identity-conflict reads.
 * Why: Conflict filtering and SQL shape are shared by diagnostics and telemetry, but database execution belongs in Infrastructure.
 * Role: Application-layer read specification with no PDO or persistence dependency.
 * Audit: Keep this class side-effect free; execute the specification through an Infrastructure query adapter.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Federation;

final class CatalogFederationConflictListService
{
    /** @return array{sql:string,args:list<mixed>} */
    public static function filter(int $peerId, bool $ignoreBaseGame): array
    {
        $where = [];
        $args = [];
        if ($peerId > 0) {
            $where[] = 'pf.peer_id=?';
            $args[] = $peerId;
        }
        if ($ignoreBaseGame) {
            $where[] = 'COALESCE(pf.is_base_game,0)=0';
        }
        return ['sql' => implode(' AND ', $where), 'args' => $args];
    }

    /** @return array{sql:string,args:list<mixed>} */
    public static function countQuery(int $peerId, bool $ignoreBaseGame): array
    {
        $filter = self::filter($peerId, $ignoreBaseGame);
        $sql = 'SELECT COUNT(*) c' . self::fromSql();
        if ($filter['sql'] !== '') {
            $sql .= ' AND ' . $filter['sql'];
        }
        return ['sql' => $sql, 'args' => $filter['args']];
    }

    public static function fromSql(): string
    {
        return ' FROM ue_federation_peer_files pf'
            . ' JOIN ue_federation_peers p ON p.id=pf.peer_id'
            . ' JOIN ue_files f ON f.scan_status="verified" AND ('
            . '(COALESCE(pf.package_guid,"")<>"" AND f.package_guid=pf.package_guid '
            . 'AND COALESCE(pf.md5,"")<>"" AND f.md5<>pf.md5)'
            . ' OR '
            . '(COALESCE(pf.md5,"")<>"" AND f.md5=pf.md5 AND ('
            . 'COALESCE(f.package_guid,"")<>COALESCE(pf.package_guid,"") OR f.file_size<>pf.file_size))'
            . ') WHERE 1=1';
    }
}
