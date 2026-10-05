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
     * @return list<array<string,mixed>>
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

    /** @return list<array<string,mixed>> */
    private function selectClassic(
        int $gameId,
        int $consumerFileId,
        array $consumer,
        array $options
    ): array {
        $resolver = $this->classicResolver($consumer);
        $baseline = $resolver($consumer, [], $options);
        $packageKeyKind = Uedb5SqlProjectionContract::classicPackageKeyKindForImportSchema(
            (string)($consumer['section_schemas']['imports'] ?? '')
        );
        $requirements = [];
        foreach ($baseline as $result) {
            $result = (array)$result;
            if (str_starts_with((string)($result['reason'] ?? ''), 'source_irrelevant_name_none')) {
                continue;
            }
            $packageName = (string)($result['provider_package'] ?? '');
            if ($packageName === '' || (string)($result['status'] ?? '') === 'common') {
                continue;
            }
            $requirementKey = $packageKeyKind === Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME
                ? CatalogUnrealIdentityHash::fnameKey($packageName)
                : CatalogUnrealIdentityHash::nameKey($packageName);
            $requirements[$requirementKey] ??= $packageName;
        }

        $selected = [];
        foreach ($requirements as $packageName) {
            $packageKey = Uedb5SqlProjectionContract::classicPackageKeyBinary((string)$packageName, $packageKeyKind);
            $candidates = $this->candidateRows(
                $gameId,
                $consumerFileId,
                $packageKeyKind,
                $packageKey
            );
            // Epic selects one package/linker before VerifyImport inspects exports.
            // candidateRows() discovers the known physical candidate set only.
            // If more than one candidate remains, the missing runtime search/mount
            // order is represented as ambiguity rather than guessed from DB order
            // or candidate contents.
            if (count($candidates) > 1) {
                $selected[] = [
                    'game_id' => $gameId,
                    'file_id' => null,
                    'package_name' => (string)$packageName,
                    'selection_status' => 'ambiguous',
                    'candidate_file_ids' => array_values(array_map(
                        static fn(array $candidate): int => (int)$candidate['file_id'],
                        $candidates
                    )),
                ];
                continue;
            }
            $provider = $candidates[0] ?? null;
            if (is_array($provider)) {
                $selected[] = [
                    'game_id' => $gameId,
                    'file_id' => (int)$provider['file_id'],
                    'package_name' => (string)$packageName,
                    'selection_status' => 'selected',
                ];
            }
        }
        return $selected;
    }

    /** @return list<array<string,mixed>> */
    private function selectZen(int $gameId, int $consumerFileId, array $consumer): array
    {
        $baseline = Uedb5Ue5ZenDependencyResolver::resolve($consumer, []);
        $requirements = [];
        foreach ($baseline as $row) {
            $row = (array)$row;
            $packageId = strtoupper(trim((string)($row['provider_lookup_package_id'] ?? $row['required_package_id'] ?? '')));
            if ($packageId !== '') {
                // Never use raw FPackageId text as a PHP array key: an all-decimal
                // 16-hex ID is coerced to int and loses its string identity.
                $requirements['package:' . $packageId] = $packageId;
            }
        }
        $selected = [];
        foreach ($requirements as $packageId) {
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
            // Epic's global import store is keyed by exact FPackageId and keeps
            // a 1:1 PackageId->package relationship. Public-export hashes resolve
            // inside that selected package; they must not select a different file.
            if (count($candidates) > 1) {
                $selected[] = [
                    'game_id' => $gameId,
                    'file_id' => null,
                    'package_id' => $packageId,
                    'selection_status' => 'ambiguous',
                    'candidate_file_ids' => array_values(array_map(
                        static fn(array $candidate): int => (int)$candidate['file_id'],
                        $candidates
                    )),
                ];
                continue;
            }
            $provider = $candidates[0] ?? null;
            if (is_array($provider)) {
                $selected[] = [
                    'game_id' => $gameId,
                    'file_id' => (int)$provider['file_id'],
                    'package_id' => $packageId,
                    'selection_status' => 'selected',
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
}
