<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves the effective federation base-game policy for parent, child and standalone sites.
 * Why: Policy interpretation and row visibility are domain decisions; schema checks and cached-parent persistence belong to separate collaborators.
 * Role: Infrastructure federation policy service preserving the existing parent-controlled base-game behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;

final class CatalogFederationBaseGamePolicyService
{
    private readonly CatalogFederationPolicySchemaGuard $schemaGuard;
    private readonly CatalogFederationParentPolicyStore $parentPolicyStore;

    public function __construct(private readonly PDO $db)
    {
        $this->schemaGuard = new CatalogFederationPolicySchemaGuard($db);
        $this->parentPolicyStore = new CatalogFederationParentPolicyStore($db, $this->schemaGuard);
    }

    public static function boolValue(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,mixed> */
    public function parentPolicy(): array
    {
        $this->schemaGuard->ensure();
        return [
            'ignore_base_game_files' => self::boolValue(\fed_setting($this->db, 'ignore_base_game_files', '1'), true),
            'missing_dependency_exception' => false,
        ];
    }

    /** @param array<string,mixed>|null $parentPeer */
    public function ignoreBaseGameFiles(?array $parentPeer = null): bool
    {
        $this->schemaGuard->ensure();
        $role = strtolower(trim((string)\fed_setting($this->db, 'site_role', 'standalone')));
        if ($role !== 'child') {
            return self::boolValue(\fed_setting($this->db, 'ignore_base_game_files', '1'), true);
        }

        $peer = $parentPeer ?? $this->parentPolicyStore->activeParentPeer();
        if (!$peer) {
            return true;
        }

        $policy = CatalogFederationParentPolicyStore::cachedPolicy($peer);
        if ($policy === null) {
            return true;
        }

        return self::boolValue($policy['ignore_base_game_files'] ?? true, true);
    }

    /** @param array<string,mixed>|null $parentPeer */
    public function baseGameAllowed(?array $parentPeer = null): bool
    {
        return !$this->ignoreBaseGameFiles($parentPeer);
    }

    /** @param array<string,mixed> $row @param array<string,mixed>|null $parentPeer */
    public function rowVisible(array $row, ?array $parentPeer = null): bool
    {
        return $this->baseGameAllowed($parentPeer) || empty($row['is_base_game']);
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed>|null $parentPeer @return list<array<string,mixed>> */
    public function filterRows(array $rows, ?array $parentPeer = null): array
    {
        if ($this->baseGameAllowed($parentPeer)) {
            return array_values($rows);
        }

        return array_values(array_filter(
            $rows,
            static fn(mixed $row): bool => is_array($row) && empty($row['is_base_game'])
        ));
    }
}
