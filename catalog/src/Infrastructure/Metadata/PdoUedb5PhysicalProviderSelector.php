<?php
/** Selects one physical staged UEDB5 provider per required package identity. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

final class PdoUedb5PhysicalProviderSelector
{
    private Uedb5MetadataReader $reader;

    public function __construct(
        private readonly PDO $db,
        string $storageRoot
    ) {
        $this->reader = new Uedb5MetadataReader($storageRoot);
    }

    /**
     * @param array{common_packages?:list<string>,class_remaps?:array<string,string>} $options
     * @return list<array{game_id:int,file_id:int,package_name?:string}>
     */
    public function select(int $gameId, int $fileId, array $options = []): array
    {
        if ($gameId < 1 || $fileId < 1) {
            throw new RuntimeException('UEDB5 provider selection requires positive game/file identity.');
        }
        $consumer = $this->reader->snapshot($gameId, $fileId);
        $family = (string)($consumer['package_family'] ?? '');
        if ($family === Uedb5ZenPackageReader::PACKAGE_FAMILY) {
            return $this->selectZen($gameId, $fileId, $consumer);
        }
        if (!in_array($family, [
            Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,
            Uedb5Ut4SnapshotBuilder::PACKAGE_FAMILY,
        ], true)) {
            throw new RuntimeException('No V5 provider selector is registered for package_family ' . $family . '.');
        }
        return $this->selectClassic($gameId, $fileId, $consumer, $options);
    }

    /** @return list<array{game_id:int,file_id:int,package_name:string}> */
    private function selectClassic(
        int $gameId,
        int $consumerFileId,
        array $consumer,
        array $options
    ): array {
        $resolver = $this->classicResolver($consumer);
        $baseline = $resolver($consumer, [], $options);
        $imports = $this->indexedImports($consumer);
        $requirements = [];
        foreach ($baseline as $index => $result) {
            $result = (array)$result;
            $packageName = trim((string)($result['provider_package'] ?? ''));
            if ($packageName === '' || (string)($result['status'] ?? '') === 'common') {
                continue;
            }
            $key = CatalogUnrealIdentityHash::nameKey($packageName);
            if (!isset($requirements[$key])) {
                $requirements[$key] = ['package_name' => $packageName, 'object_indexes' => []];
            }
            $import = (array)($imports[(int)$index] ?? []);
            if (!$this->isPackageImport($import)) {
                $requirements[$key]['object_indexes'][] = (int)$index;
            }
        }

        $selected = [];
        foreach ($requirements as $requirement) {
            $packageName = (string)$requirement['package_name'];
            $packageKey = md5(CatalogUnrealIdentityHash::nameKey($packageName), true);
            $candidates = $this->candidateRows(
                $gameId,
                $consumerFileId,
                Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,
                $packageKey
            );
            $best = $this->bestClassicCandidate(
                $gameId,
                $consumer,
                $packageName,
                (array)$requirement['object_indexes'],
                $candidates,
                $resolver,
                $options
            );
            if ($best !== null) {
                $selected[] = [
                    'game_id' => $gameId,
                    'file_id' => (int)$best['file_id'],
                    'package_name' => $packageName,
                ];
            }
        }
        return $selected;
    }

    /** @return list<array{game_id:int,file_id:int}> */
    private function selectZen(int $gameId, int $consumerFileId, array $consumer): array
    {
        $baseline = Uedb5Ue5ZenDependencyResolver::resolve($consumer, []);
        $requirements = [];
        foreach ($baseline as $row) {
            $row = (array)$row;
            $packageId = strtoupper(trim((string)($row['required_package_id'] ?? '')));
            if ($packageId === '') { continue; }
            if (!isset($requirements[$packageId])) {
                $requirements[$packageId] = [];
            }
            if (in_array((string)($row['source_section'] ?? ''), ['imports', 'cell_imports'], true)
                && trim((string)($row['required_object_identity'] ?? '')) !== '') {
                $requirements[$packageId][] = [
                    'source_section' => (string)$row['source_section'],
                    'source_index' => (int)$row['source_index'],
                ];
            }
        }
        $selected = [];
        foreach ($requirements as $packageId => $requiredObjects) {
            if (preg_match('/^[0-9A-F]{16}$/', $packageId) !== 1) {
                throw new RuntimeException('Zen dependency provider FPackageId is invalid.');
            }
            $packageKey = hex2bin($packageId);
            if (!is_string($packageKey) || strlen($packageKey) !== 8) {
                throw new RuntimeException('Could not encode Zen dependency provider key.');
            }
            $candidates = $this->candidateRows(
                $gameId,
                $consumerFileId,
                Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID,
                $packageKey
            );
            $best = $this->bestZenCandidate(
                $gameId,
                $consumer,
                (array)$requiredObjects,
                $candidates
            );
            if ($best !== null) {
                $selected[] = [
                    'game_id' => $gameId,
                    'file_id' => (int)$best['file_id'],
                ];
            }
        }
        return $selected;
    }
    /** @return callable(array,array,array):array */
    private function classicResolver(array $consumer): callable
    {
        $schema = strtolower(trim((string)($consumer['section_schemas']['imports'] ?? '')));
        if (str_starts_with($schema, 'ue5.')) {
            return static fn(array $snapshot, array $providers, array $options): array =>
                Uedb5Ue5ClassicVerifyImportResolver::resolve($snapshot, $providers);
        }
        return static fn(array $snapshot, array $providers, array $options): array =>
            Uedb5ClassicDependencyResolver::resolve($snapshot, $providers, $options);
    }

    /** @param list<int> $requiredIndexes @param list<array<string,mixed>> $candidates */
    private function bestClassicCandidate(
        int $gameId,
        array $consumer,
        string $packageName,
        array $requiredIndexes,
        array $candidates,
        callable $resolver,
        array $options
    ): ?array {
        if ($candidates === []) { return null; }
        if (count($candidates) === 1 || $requiredIndexes === []) { return $candidates[0]; }
        $best = null;
        $bestMatches = -1;
        $bestRedirectors = -1;
        foreach ($candidates as $candidate) {
            $provider = [
                'package_name' => $packageName,
                'provider_id' => (int)$candidate['file_id'],
                'snapshot' => $this->reader->snapshot($gameId, (int)$candidate['file_id']),
            ];
            $results = $resolver($consumer, [$provider], $options);
            $matchCount = 0;
            $redirectorCount = 0;
            foreach ($requiredIndexes as $index) {
                $result = (array)($results[(int)$index] ?? []);
                if ((string)($result['status'] ?? '') === 'resolved') {
                    $matchCount++;
                    continue;
                }
                if ($this->isRedirectorEvidence($result)) {
                    $redirectorCount++;
                }
            }
            if ($matchCount > $bestMatches
                || ($matchCount === $bestMatches && $redirectorCount > $bestRedirectors)) {
                $best = $candidate;
                $bestMatches = $matchCount;
                $bestRedirectors = $redirectorCount;
            }
            if ($matchCount === count($requiredIndexes)) { break; }
        }
        return $best;
    }

    /** @param list<array{source_section:string,source_index:int}> $requiredObjects */
    private function bestZenCandidate(
        int $gameId,
        array $consumer,
        array $requiredObjects,
        array $candidates
    ): ?array {
        if ($candidates === []) { return null; }
        if ($requiredObjects === []) { return $candidates[0]; }
        $best = null;
        $bestMatches = -1;
        foreach ($candidates as $candidate) {
            $rows = Uedb5Ue5ZenDependencyResolver::resolve($consumer, [[
                'provider_id' => (int)$candidate['file_id'],
                'snapshot' => $this->reader->snapshot($gameId, (int)$candidate['file_id']),
            ]]);
            $outcomes = [];
            foreach ($rows as $row) {
                $row = (array)$row;
                $key = (string)($row['source_section'] ?? '') . ':' . (int)($row['source_index'] ?? -1);
                $outcomes[$key] = (string)($row['outcome'] ?? '');
            }
            $matchCount = 0;
            foreach ($requiredObjects as $required) {
                $key = (string)$required['source_section'] . ':' . (int)$required['source_index'];
                if (($outcomes[$key] ?? '') === 'resolved') { $matchCount++; }
            }
            if ($matchCount > $bestMatches) {
                $best = $candidate;
                $bestMatches = $matchCount;
            }
            if ($matchCount === count($requiredObjects)) { break; }
        }
        return $best;
    }
    /** @return list<array<string,mixed>> */
    private function candidateRows(
        int $gameId,
        int $consumerFileId,
        int $packageKeyKind,
        string $packageKey
    ): array {
        $statement = $this->db->prepare(
            'SELECT p.file_id,p.source_kind,p.source_id,v.package_name,f.uploaded_at '
            . 'FROM ue_uedb5_provider_keys p '
            . 'JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
            . 'WHERE p.game_id=? AND p.package_key_kind=? AND p.package_key=? '
            . 'AND f.scan_status="verified" '
            . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
            . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
            . 'ORDER BY (p.source_kind=1) DESC,(p.file_id=?) DESC,'
            . 'f.uploaded_at DESC,p.source_id ASC,p.file_id ASC'
        );
        $statement->execute([$gameId, $packageKeyKind, $packageKey, $consumerFileId]);
        $rows = [];
        $seen = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $providerFileId = (int)($row['file_id'] ?? 0);
            if ($providerFileId < 1 || isset($seen[$providerFileId])) { continue; }
            $seen[$providerFileId] = true;
            $rows[] = $row;
        }
        return $rows;
    }
    /** @return array<int,array<string,mixed>> */
    private function indexedImports(array $snapshot): array
    {
        $indexed = [];
        foreach ((array)($snapshot['sections']['imports'] ?? []) as $fallback => $row) {
            if (!is_array($row)) { continue; }
            $index = array_key_exists('index', $row) ? (int)$row['index'] : (int)$fallback;
            $indexed[$index] = $row;
        }
        return $indexed;
    }

    private function isPackageImport(array $import): bool
    {
        $className = $import['class_name'] ?? null;
        if (is_array($className)) {
            $className = (string)($className['text'] ?? '');
        }
        return (int)($import['outer_index'] ?? 0) === 0
            && CatalogUnrealIdentityHash::nameKey((string)$className)
                === CatalogUnrealIdentityHash::nameKey('Package');
    }

    private function isRedirectorEvidence(array $result): bool
    {
        $reason = strtolower(trim((string)($result['reason'] ?? '')));
        if ($reason === '') { return false; }
        return str_contains($reason, 'redirector');
    }
}
