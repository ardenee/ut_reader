<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns reusable game-profile listing, validation, persistence and deletion policy.
 * Why: Compatibility-rule validation, discovery-extension normalization and assigned-game checks should not live in Presentation.
 * Role: Infrastructure/application service over the existing GameProfiles compatibility contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use RuntimeException;

final class CatalogGameProfileAdminService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/GameProfiles.php';
    }

    /** @return list<array<string,mixed>> */
    public function profiles(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT p.id profile_id,p.profile_name,p.engine_key profile_engine,p.allowed_extensions_json,'
            . 'p.compatibility_rules_json,p.package_version_min,p.package_version_max,'
            . 'p.licensee_version_min,p.licensee_version_max,p.confidence_policy,p.notes,'
            . 'COUNT(g.id) assigned_games '
            . 'FROM ue_game_profiles p LEFT JOIN ue_games g ON g.profile_id=p.id '
            . 'GROUP BY p.id ORDER BY COALESCE(p.profile_name,p.engine_key),p.id'
        );
    }

    /** @param array<string,mixed> $input */
    public function save(string $action, array $input): int
    {
        $profileId = $action === 'update' ? (int)($input['profile_id'] ?? 0) : 0;
        $name = trim((string)($input['profile_name'] ?? ''));
        $engine = strtoupper(trim((string)($input['engine_key'] ?? '')));
        $extensions = (string)($input['extensions'] ?? '');
        $compatibilityRules = (string)($input['compatibility_rules_json'] ?? '');
        $versionMin = trim((string)($input['package_version_min'] ?? ''));
        $versionMax = trim((string)($input['package_version_max'] ?? ''));
        $licenseeMin = trim((string)($input['licensee_version_min'] ?? ''));
        $licenseeMax = trim((string)($input['licensee_version_max'] ?? ''));
        $policy = in_array((string)($input['confidence_policy'] ?? 'normal'), ['strict', 'normal', 'loose'], true)
            ? (string)$input['confidence_policy']
            : 'normal';
        $notes = trim((string)($input['notes'] ?? ''));

        if ($name === '' || $engine === '') {
            throw new RuntimeException('Profile name and engine key are required.');
        }

        $rulesJson = $this->compatibilityRulesJson($compatibilityRules);
        $extensionsJson = $this->extensionsJson($extensions);
        $values = [
            $name,
            $engine,
            $extensionsJson,
            $rulesJson,
            $versionMin === '' ? null : (int)$versionMin,
            $versionMax === '' ? null : (int)$versionMax,
            $licenseeMin === '' ? null : (int)$licenseeMin,
            $licenseeMax === '' ? null : (int)$licenseeMax,
            $policy,
            $notes ?: null,
        ];

        if ($profileId > 0) {
            if (!\catalog_one($this->db, 'SELECT id FROM ue_game_profiles WHERE id=?', [$profileId])) {
                throw new RuntimeException('Profile not found.');
            }
            $this->db->prepare(
                'UPDATE ue_game_profiles SET profile_name=?,game_id=NULL,engine_key=?,allowed_extensions_json=?,'
                . 'compatibility_rules_json=?,package_version_min=?,package_version_max=?,licensee_version_min=?,'
                . 'licensee_version_max=?,confidence_policy=?,notes=?,is_active=1 WHERE id=?'
            )->execute(array_merge($values, [$profileId]));
            return $profileId;
        }

        $this->db->prepare(
            'INSERT INTO ue_game_profiles(profile_name,game_id,engine_key,allowed_extensions_json,'
            . 'compatibility_rules_json,package_version_min,package_version_max,licensee_version_min,'
            . 'licensee_version_max,confidence_policy,notes,is_active) VALUES(?,NULL,?,?,?,?,?,?,?,?,?,1)'
        )->execute($values);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $profileId): void
    {
        $profile = \catalog_one(
            $this->db,
            'SELECT id,profile_name,engine_key FROM ue_game_profiles WHERE id=?',
            [$profileId]
        );
        if (!$profile) {
            throw new RuntimeException('Profile not found.');
        }

        $games = \catalog_all($this->db, 'SELECT name FROM ue_games WHERE profile_id=? ORDER BY name', [$profileId]);
        if ($games !== []) {
            $names = implode(', ', array_map(static fn(array $game): string => (string)$game['name'], $games));
            throw new RuntimeException(
                'This game profile is in use by: ' . $names
                . '. Remove or change the profile on those game(s) first, then delete it.'
            );
        }
        $this->db->prepare('DELETE FROM ue_game_profiles WHERE id=?')->execute([$profileId]);
    }

    /**
     * Extensions are retained only as discovery/UI hints for source enumeration.
     * They do not participate in package engine detection or reader selection.
     */
    private function extensionsJson(string $text): string
    {
        $parts = preg_split('/[,\s]+/', strtolower(trim($text))) ?: [];
        $parts = array_values(array_unique(array_filter(
            array_map(static fn(string $value): string => trim($value, '. '), $parts),
            static fn(string $value): bool => $value !== ''
        )));
        return json_encode($parts, JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function compatibilityRulesJson(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $rules = json_decode($text, true);
        if (!is_array($rules)) {
            throw new RuntimeException('Compatibility rules must be valid JSON array data.');
        }
        foreach ($rules as $index => &$rule) {
            if (!is_array($rule) || trim((string)($rule['detected_engine'] ?? '')) === '') {
                throw new RuntimeException('Compatibility rule #' . ($index + 1) . ' requires detected_engine.');
            }
            // Filename/extension conditions are non-authoritative. Strip legacy
            // copies when the profile is saved so they cannot imply otherwise.
            unset($rule['extensions'], $rule['extension'], $rule['filename'], $rule['filenames']);
        }
        unset($rule);
        return json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
