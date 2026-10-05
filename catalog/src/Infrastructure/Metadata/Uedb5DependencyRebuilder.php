<?php
/**
 * Rebuilds authoritative UEDB5 dependency-result sections without publishing SQL or touching UEDB4.
 * Provider files are explicitly selected physical UEDB5 snapshots; source-specific resolvers remain responsible
 * for Unreal semantics while this class normalizes their results to the frozen five-state V5 contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5DependencyRebuilder
{
    public const SECTION = 'dependency_results';
    public const CLASSIC_SCHEMA = 'ue5.classic.dependency-result.v1';
    public const UNREAL_CLASSIC_SCHEMA = 'unreal.classic.dependency-result.v1';
    public const ZEN_SCHEMA = 'ue5.zen.dependency-result.v1';
    private const CLASSIC_RESOLVER_POLICY = 'ue5-5.8.3-classic-verify-import-v1';
    private const OUTCOME_CODES = [
        'missing' => 0,
        'resolved' => 1,
        'package_only' => 2,
        'common' => 3,
        'unresolved' => 4,
    ];

    public function __construct(
        private readonly Uedb5MetadataReader $reader,
        private readonly Uedb5MetadataSnapshotWriter $writer
    ) {
    }

    /**
     * @param list<array{game_id:int,file_id:int,package_name?:string}> $selectedProviders
     * @param array{common_packages?:list<string>,class_remaps?:array<string,string>} $options
     * @return array<string,mixed>
     */
    public function rebuild(int $gameId, int $fileId, array $selectedProviders, array $options = []): array
    {
        $consumer = $this->reader->snapshot($gameId, $fileId);
        $providers = $this->loadProviders($selectedProviders);
        $family = (string)($consumer['package_family'] ?? '');

        if ($family === Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY
            && (string)($consumer['source_policy'] ?? '') === Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY) {
            $rows = $this->rebuildClassic($consumer, $providers);
            $schema = self::CLASSIC_SCHEMA;
        } elseif ($family === Uedb5ZenPackageReader::PACKAGE_FAMILY) {
            $rows = $this->rebuildZen($consumer, $providers);
            $schema = self::ZEN_SCHEMA;
        } elseif (in_array($family, [Uedb5Ut99SnapshotBuilder::PACKAGE_FAMILY, Uedb5Ut4SnapshotBuilder::PACKAGE_FAMILY], true)) {
            $rows = $this->rebuildUnrealClassic($consumer, $providers, $options);
            $schema = self::UNREAL_CLASSIC_SCHEMA;
        } else {
            throw new RuntimeException('No UEDB5 dependency resolver is registered for package_family ' . $family . '.');
        }

        $consumer['sections'][self::SECTION] = $rows;
        $consumer['section_schemas'][self::SECTION] = $schema;
        $written = $this->writer->write($consumer);
        $this->reader->clearCache($gameId, $fileId);

        return $written + [
            'dependency_count' => count($rows),
            'dependency_outcomes' => $this->outcomeCounts($rows),
            'dependency_schema' => $schema,
        ];
    }

    /** @param list<array<string,mixed>> $selectedProviders @return list<array<string,mixed>> */
    private function loadProviders(array $selectedProviders): array
    {
        $loaded = [];
        foreach ($selectedProviders as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('UEDB5 provider selection contains a non-row value.');
            }
            $gameId = (int)($row['game_id'] ?? 0);
            $fileId = (int)($row['file_id'] ?? 0);
            if ($gameId < 1 || $fileId < 1) {
                throw new RuntimeException('UEDB5 provider selection requires positive game_id and file_id.');
            }
            $snapshot = $this->reader->snapshot($gameId, $fileId);
            $packageName = trim((string)($row['package_name'] ?? ''));
            if ($packageName === '') {
                $packageName = trim((string)($snapshot['file']['package_name'] ?? ''));
            }
            $loaded[] = [
                'game_id' => $gameId,
                'file_id' => $fileId,
                'package_name' => $packageName,
                'snapshot' => $snapshot,
            ];
        }
        return $loaded;
    }

    /** @param array<string,mixed> $consumer @param list<array<string,mixed>> $providers */
    private function rebuildClassic(array $consumer, array $providers): array
    {
        $selected = [];
        foreach ($providers as $provider) {
            $selected[] = [
                'package_name' => (string)$provider['package_name'],
                'provider_id' => (int)$provider['file_id'],
                'snapshot' => (array)$provider['snapshot'],
            ];
        }
        $resolved = Uedb5Ue5ClassicVerifyImportResolver::resolve($consumer, $selected);
        $imports = [];
        foreach ((array)$consumer['sections']['imports'] as $fallback => $row) {
            $row = (array)$row;
            $index = array_key_exists('index', $row) ? (int)$row['index'] : (int)$fallback;
            $imports[$index] = $row;
        }

        $rows = [];
        foreach ($resolved as $importIndex => $result) {
            $importIndex = (int)$importIndex;
            $import = (array)($imports[$importIndex] ?? []);
            $rows[] = $this->normalizeClassicRow(
                $consumer,
                $importIndex,
                $import,
                (array)$result
            );
        }
        return $rows;
    }

    /** @param array<string,mixed> $consumer @param list<array<string,mixed>> $providers */
    private function rebuildUnrealClassic(array $consumer, array $providers, array $options): array
    {
        $selected = [];
        foreach ($providers as $provider) {
            $selected[] = [
                'package_name' => (string)$provider['package_name'],
                'provider_id' => (int)$provider['file_id'],
                'snapshot' => (array)$provider['snapshot'],
            ];
        }
        $resolved = Uedb5ClassicDependencyResolver::resolve($consumer, $selected, $options);
        $imports = [];
        foreach ((array)$consumer['sections']['imports'] as $fallback => $row) {
            $row = (array)$row;
            $index = array_key_exists('index', $row) ? (int)$row['index'] : (int)$fallback;
            $imports[$index] = $row;
        }

        $rows = [];
        foreach ($resolved as $importIndex => $result) {
            $importIndex = (int)$importIndex;
            $rows[] = $this->normalizeUnrealClassicRow(
                $consumer,
                $importIndex,
                (array)($imports[$importIndex] ?? []),
                (array)$result
            );
        }
        return $rows;
    }

    /** @param array<string,mixed> $consumer @param list<array<string,mixed>> $providers */
    private function rebuildZen(array $consumer, array $providers): array
    {
        $selected = [];
        foreach ($providers as $provider) {
            $selected[] = [
                'provider_id' => (int)$provider['file_id'],
                'snapshot' => (array)$provider['snapshot'],
            ];
        }
        $resolved = Uedb5Ue5ZenDependencyResolver::resolve($consumer, $selected);
        $rows = [];
        foreach ($resolved as $row) {
            $row = (array)$row;
            $outcome = (string)($row['outcome'] ?? '');
            $this->assertCanonicalOutcome($outcome);
            $row['outcome_code'] = self::OUTCOME_CODES[$outcome];
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $consumer @param array<string,mixed> $import @param array<string,mixed> $result */
    private function normalizeUnrealClassicRow(
        array $consumer,
        int $importIndex,
        array $import,
        array $result
    ): array {
        $outcome = (string)($result['status'] ?? '');
        $this->assertCanonicalOutcome($outcome);
        $providerPackage = trim((string)($result['provider_package'] ?? ''));
        $providerId = $result['provider_id'] ?? null;
        $objectName = $this->fnameText($import['object_name'] ?? null);
        $classPackage = $this->fnameText($import['class_package'] ?? null);
        $className = $this->fnameText($import['class_name'] ?? null);
        $packageImport = (int)($import['outer_index'] ?? 0) === 0
            && CatalogUnrealIdentityHash::nameKey($className) === CatalogUnrealIdentityHash::nameKey('Package');
        $sourceIrrelevantNone = (string)($result['reason'] ?? '') === 'source_irrelevant_name_none';
        $dependencyClass = (string)($result['dependency_class'] ?? 'hard');

        return [
            'dependency_kind' => 'ClassicImport',
            'source_section' => 'imports',
            'source_index' => $importIndex,
            'required_package_identity' => $providerPackage === '' ? null : [
                'kind' => 'package_name',
                'value' => $providerPackage,
            ],
            'required_object_identity' => (($packageImport && !$sourceIrrelevantNone) || $objectName === '') ? null : [
                'kind' => 'classic_import',
                'object_name' => $objectName,
                'class_package' => $classPackage,
                'class_name' => $className,
                'outer_index' => (int)($import['outer_index'] ?? 0),
            ],
            'dependency_class' => $dependencyClass,
            'optional' => false,
            'hard' => $dependencyClass === 'hard',
            'outcome' => $outcome,
            'outcome_code' => self::OUTCOME_CODES[$outcome],
            'selected_provider_file_id' => $providerId !== null ? (int)$providerId : null,
            'selected_provider_package_identity' => $providerId !== null && $providerPackage !== '' ? [
                'kind' => 'package_name',
                'value' => $providerPackage,
            ] : null,
            'selected_provider_object' => isset($result['export_index']) && $result['export_index'] !== null ? [
                'export_index' => (int)$result['export_index'],
            ] : null,
            'resolver_policy' => (string)($result['resolver_policy'] ?? ''),
            'source_policy' => (string)($consumer['source_policy'] ?? ''),
            'reason_code' => (string)($result['reason'] ?? ''),
            'resolver_detail' => $result,
        ];
    }

    /**
     * @param array<string,mixed> $consumer
     * @param array<string,mixed> $import
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function normalizeClassicRow(
        array $consumer,
        int $importIndex,
        array $import,
        array $result
    ): array {
        $status = (string)($result['status'] ?? '');
        $optional = !empty($import['b_import_optional_present']) && !empty($import['b_import_optional']);
        $outcome = match ($status) {
            'resolved' => 'resolved',
            'package_only' => 'package_only',
            'missing', 'optional_missing', 'private_export' => 'missing',
            'runtime_only', 'invalid', 'ignored' => 'unresolved',
            default => throw new RuntimeException('Unknown UE5 classic dependency resolver status: ' . $status),
        };
        $this->assertCanonicalOutcome($outcome);

        $runtimeDerived = in_array($status, ['runtime_only', 'ignored'], true);
        $dependencyClass = $runtimeDerived ? 'runtime_derived' : ($optional ? 'optional' : 'hard');
        $hard = $dependencyClass === 'hard';
        $providerPackage = trim((string)($result['provider_package'] ?? ''));
        $objectName = $this->fnameText($import['object_name'] ?? null);
        $classPackage = $this->fnameText($import['class_package'] ?? null);
        $className = $this->fnameText($import['class_name'] ?? null);

        return [
            'dependency_kind' => 'ClassicImport',
            'source_section' => 'imports',
            'source_index' => $importIndex,
            'required_package_identity' => $providerPackage === '' ? null : [
                'kind' => 'package_name',
                'value' => $providerPackage,
            ],
            'required_object_identity' => $objectName === '' ? null : [
                'kind' => 'classic_import',
                'object_name' => $objectName,
                'class_package' => $classPackage,
                'class_name' => $className,
                'outer_index' => (int)($import['outer_index'] ?? 0),
            ],
            'dependency_class' => $dependencyClass,
            'optional' => $optional,
            'hard' => $hard,
            'outcome' => $outcome,
            'outcome_code' => self::OUTCOME_CODES[$outcome],
            'selected_provider_file_id' => isset($result['provider_id']) ? (int)$result['provider_id'] : null,
            'selected_provider_package_identity' => $providerPackage === '' ? null : [
                'kind' => 'package_name',
                'value' => $providerPackage,
            ],
            'selected_provider_object' => isset($result['export_index']) && $result['export_index'] !== null ? [
                'export_index' => (int)$result['export_index'],
            ] : null,
            'resolver_policy' => self::CLASSIC_RESOLVER_POLICY,
            'source_policy' => (string)$consumer['source_policy'],
            'reason_code' => $this->classicReasonCode($status),
            'resolver_detail' => $result,
        ];
    }

    private function classicReasonCode(string $status): string
    {
        return match ($status) {
            'resolved' => 'verify_import_match',
            'package_only' => 'package_linker_only',
            'missing' => 'verify_import_missing',
            'optional_missing' => 'optional_import_missing',
            'private_export' => 'private_export_rejected',
            'runtime_only' => 'runtime_state_required',
            'invalid' => 'invalid_import_graph',
            'ignored' => 'ignored_none_identity',
            default => throw new RuntimeException('Unknown UE5 classic dependency reason status: ' . $status),
        };
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function outcomeCounts(array $rows): array
    {
        $counts = array_fill_keys(array_keys(self::OUTCOME_CODES), 0);
        foreach ($rows as $row) {
            $outcome = (string)($row['outcome'] ?? '');
            $this->assertCanonicalOutcome($outcome);
            $counts[$outcome]++;
        }
        return $counts;
    }

    private function assertCanonicalOutcome(string $outcome): void
    {
        if (!array_key_exists($outcome, self::OUTCOME_CODES)) {
            throw new RuntimeException('Dependency result uses a non-canonical UEDB5 outcome: ' . $outcome);
        }
    }

    private function fnameText(mixed $value): string
    {
        // Preserve serialized FName text exactly. Whitespace-only FNames are
        // valid identity values and must not collapse to an absent object name.
        return is_array($value) ? (string)($value['text'] ?? '') : '';
    }
}
