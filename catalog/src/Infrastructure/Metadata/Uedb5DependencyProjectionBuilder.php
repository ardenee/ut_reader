<?php
/** Builds exact SQL dependency accelerators from authoritative UEDB5 dependency_results. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5DependencyProjectionBuilder
{
    /** @param array<string,mixed> $snapshot @return array{dependency_edges:array,dependency_packages:array} */
    public static function build(array $snapshot): array
    {
        $fileId = (int)($snapshot['file']['id'] ?? 0);
        $gameId = (int)($snapshot['file']['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('UEDB5 dependency projection requires positive file/game identity.');
        }
        $rows = array_values((array)($snapshot['sections']['dependency_results'] ?? []));
        $edges = [];
        $packages = [];
        $zenNames = self::zenPackageNames($snapshot);
        foreach ($rows as $row) {
            $row = (array)$row;
            $edge = self::edge($fileId, $row);
            $edges[] = $edge;
            if ($edge['required_package_key'] === null) { continue; }
            $packageId = $edge['required_package_key_kind'] . ':' . bin2hex((string)$edge['required_package_key']);
            if (!isset($packages[$packageId])) {
                $packages[$packageId] = self::emptyPackageSummary(
                    $gameId,
                    $fileId,
                    $edge['required_package_key_kind'],
                    (string)$edge['required_package_key'],
                    self::requiredPackageName($row, $zenNames)
                );
            }
            self::accumulate($packages[$packageId], $row, $edge);
        }
        foreach ($packages as &$summary) {
            $summary['summary_outcome'] = self::summaryOutcome($summary);
        }
        unset($summary);
        return [
            'dependency_edges' => $edges,
            'dependency_packages' => array_values($packages),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function edge(int $fileId, array $row): array
    {
        $sourceSection = (string)($row['source_section'] ?? '');
        $sourceKind = match ($sourceSection) {
            'imports' => Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,
            'cell_imports' => Uedb5SqlProjectionContract::DEP_SOURCE_CELL_IMPORT,
            'soft_package_references' => Uedb5SqlProjectionContract::DEP_SOURCE_SOFT_PACKAGE,
            'dependency_bundle_entries' => Uedb5SqlProjectionContract::DEP_SOURCE_LOAD_ORDER,
            default => throw new RuntimeException('Unknown UEDB5 dependency source section: ' . $sourceSection),
        };
        $className = (string)($row['dependency_class'] ?? 'runtime_derived');
        $classes = Uedb5SqlProjectionContract::classificationCodes();
        if (!isset($classes[$className])) {
            throw new RuntimeException('Unknown UEDB5 dependency class: ' . $className);
        }
        $outcomeName = (string)($row['outcome'] ?? '');
        $outcomes = Uedb5SqlProjectionContract::outcomeCodes();
        if (!isset($outcomes[$outcomeName])) {
            throw new RuntimeException('Unknown UEDB5 dependency outcome: ' . $outcomeName);
        }
        [$packageKind, $packageKey] = self::requiredPackageKey($row);
        [$objectKind, $objectKey] = self::requiredObjectKey($row);
        [$resolvedKind, $resolvedIndex] = self::resolvedObject($row);
        return [
            'file_id' => $fileId,
            'source_kind' => $sourceKind,
            'source_index' => (int)($row['source_index'] ?? -1),
            'classification' => $classes[$className],
            'outcome' => $outcomes[$outcomeName],
            'required_package_key_kind' => $packageKind,
            'required_package_key' => $packageKey,
            'required_object_key_kind' => $objectKind,
            'required_object_key' => $objectKey,
            'resolved_file_id' => isset($row['selected_provider_file_id']) ? (int)$row['selected_provider_file_id'] : null,
            'resolved_object_kind' => $resolvedKind,
            'resolved_object_index' => $resolvedIndex,
        ];
    }

    /** @param array<string,mixed> $row @return array{0:?int,1:?string} */
    private static function requiredPackageKey(array $row): array
    {
        $classic = $row['required_package_identity'] ?? null;
        if (is_array($classic) && ($classic['kind'] ?? null) === 'package_name') {
            $value = trim((string)($classic['value'] ?? ''));
            if ($value !== '') {
                return [
                    Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,
                    md5(CatalogUnrealIdentityHash::nameKey($value), true),
                ];
            }
        }
        $zen = strtoupper(trim((string)($row['required_package_id'] ?? '')));
        if ($zen !== '') {
            if (preg_match('/^[0-9A-F]{16}$/', $zen) !== 1) {
                throw new RuntimeException('Dependency required FPackageId is invalid.');
            }
            return [Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID, hex2bin($zen) ?: null];
        }
        return [null, null];
    }

    /** @param array<string,mixed> $row @return array{0:?int,1:?string} */
    private static function requiredObjectKey(array $row): array
    {
        if ((string)($row['dependency_kind'] ?? '') === 'ScriptImport') {
            return [null, null];
        }
        $classic = $row['required_object_identity'] ?? null;
        if (is_array($classic) && ($classic['kind'] ?? null) === 'classic_import') {
            $name = (string)($classic['object_name'] ?? '');
            if ($name !== '') {
                return [
                    Uedb5SqlProjectionContract::OBJECT_KEY_NAME,
                    md5(self::fnameKey($name), true),
                ];
            }
        }
        $zen = strtoupper(trim((string)($row['required_object_identity'] ?? '')));
        if ($zen !== '') {
            if (preg_match('/^[0-9A-F]{16}$/', $zen) !== 1) {
                throw new RuntimeException('Dependency required PublicExportHash is invalid.');
            }
            return [Uedb5SqlProjectionContract::OBJECT_KEY_PUBLIC_EXPORT_HASH, hex2bin($zen) ?: null];
        }
        return [null, null];
    }

    /** @param array<string,mixed> $row @return array{0:?int,1:?int} */
    private static function resolvedObject(array $row): array
    {
        $object = $row['selected_provider_object'] ?? null;
        if (!is_array($object)) { return [null, null]; }
        if (array_key_exists('export_index', $object)) {
            return [Uedb5SqlProjectionContract::OBJECT_KIND_EXPORT, (int)$object['export_index']];
        }
        if (array_key_exists('cell_export_index', $object)) {
            return [Uedb5SqlProjectionContract::OBJECT_KIND_CELL_EXPORT, (int)$object['cell_export_index']];
        }
        return [null, null];
    }

    /** @return array<string,array<string,mixed>> */
    private static function zenPackageNames(array $snapshot): array
    {
        $map = [];
        foreach ((array)($snapshot['sections']['imported_package_ids'] ?? []) as $row) {
            $row = (array)$row;
            $id = strtoupper(trim((string)($row['package_id'] ?? '')));
            if ($id === '') { continue; }
            $name = $row['serialized_name'] ?? null;
            if (is_array($name)) {
                $name = (string)($name['text'] ?? '');
            }
            $map[$id] = trim((string)$name);
        }
        return $map;
    }

    /** @param array<string,mixed> $row @param array<string,string> $zenNames */
    private static function requiredPackageName(array $row, array $zenNames): string
    {
        $classic = $row['required_package_identity'] ?? null;
        if (is_array($classic)) {
            return trim((string)($classic['value'] ?? ''));
        }
        $id = strtoupper(trim((string)($row['required_package_id'] ?? '')));
        return $id !== '' ? (string)($zenNames[$id] ?? $id) : '';
    }

    /** @return array<string,mixed> */
    private static function emptyPackageSummary(
        int $gameId,
        int $fileId,
        int $kind,
        string $key,
        string $name
    ): array {
        return [
            'game_id' => $gameId,
            'file_id' => $fileId,
            'package_key_kind' => $kind,
            'package_key' => $key,
            'required_package_name' => $name,
            'dependency_count' => 0,
            'resolved_count' => 0,
            'missing_count' => 0,
            'package_only_count' => 0,
            'common_count' => 0,
            'unresolved_count' => 0,
            'hard_missing_count' => 0,
            'nonhard_missing_count' => 0,
            'summary_outcome' => Uedb5SqlProjectionContract::OUTCOME_RESOLVED,
            'provider_file_id' => null,
        ];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $row @param array<string,mixed> $edge */
    private static function accumulate(array &$summary, array $row, array $edge): void
    {
        $summary['dependency_count']++;
        $outcome = (string)($row['outcome'] ?? '');
        $field = match ($outcome) {
            'resolved' => 'resolved_count',
            'missing' => 'missing_count',
            'package_only' => 'package_only_count',
            'common' => 'common_count',
            'unresolved' => 'unresolved_count',
            default => throw new RuntimeException('Unknown UEDB5 dependency outcome: ' . $outcome),
        };
        $summary[$field]++;
        if ($outcome === 'missing') {
            if (!empty($row['hard'])) { $summary['hard_missing_count']++; }
            else { $summary['nonhard_missing_count']++; }
        }
        $provider = $edge['resolved_file_id'] ?? null;
        if ($provider !== null) {
            $provider = (int)$provider;
            if ($summary['provider_file_id'] === null) {
                $summary['provider_file_id'] = $provider;
            } elseif ((int)$summary['provider_file_id'] !== $provider) {
                $summary['provider_file_id'] = null;
            }
        }
    }

    /** @param array<string,mixed> $summary */
    private static function summaryOutcome(array $summary): int
    {
        if ((int)$summary['hard_missing_count'] > 0 || (int)$summary['missing_count'] > 0) {
            return Uedb5SqlProjectionContract::OUTCOME_MISSING;
        }
        if ((int)$summary['unresolved_count'] > 0) {
            return Uedb5SqlProjectionContract::OUTCOME_UNRESOLVED;
        }
        if ((int)$summary['package_only_count'] > 0) {
            return Uedb5SqlProjectionContract::OUTCOME_PACKAGE_ONLY;
        }
        if ((int)$summary['common_count'] > 0) {
            return Uedb5SqlProjectionContract::OUTCOME_COMMON;
        }
        return Uedb5SqlProjectionContract::OUTCOME_RESOLVED;
    }

    private static function fnameKey(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
