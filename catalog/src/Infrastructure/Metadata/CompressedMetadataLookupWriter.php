<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Writes current compact global lookup projections using bounded multi-row SQL.
 * Why: Format-1 metadata publication is retired; callers must explicitly publish the authoritative current container version and codec.
 * Role: Infrastructure current metadata projection writer.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

require_once __DIR__ . '/CatalogUnrealIdentityHash.php';

final class CompressedMetadataLookupWriter
{
    private const TERM_BATCH_SIZE = 350;
    private const WRITE_BATCH_SIZE = 500;
    private const TERM_CONTENTION_ATTEMPTS = 8;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $snapshot @return array<string,int> */
    public function primeSnapshotTerms(array $snapshot, int &$sqlBatches): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Compact term dictionary priming must run outside a snapshot transaction.');
        }
        return $this->resolveTermIds($this->snapshotTermValues($snapshot), $sqlBatches);
    }

    /**
     * Rebuild only the UE1/UE2 VerifyImport projection from an existing compact snapshot.
     * The current metadata container and all other projections remain unchanged.
     *
     * @param array<string,mixed> $snapshot
     * @return array{file_id:int,rows:int,sql_batches:int}
     */
    public function rebuildLegacyVerifyImportProjection(array $snapshot): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('VerifyImport projection rebuilding requires ownership of the database transaction.');
        }
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('VerifyImport projection rebuild requires valid file and game identities.');
        }

        $sqlBatches = 0;
        $termIds = $this->primeSnapshotTerms($snapshot, $sqlBatches);
        $rows = $this->isLegacyVerifyImportGame($gameId)
            ? $this->legacyProjectionRows($snapshot, $termIds)
            : [];

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ue_legacy_export_identity_lookup WHERE file_id=?')->execute([$fileId]);
            $sqlBatches++;
            foreach (array_chunk($rows, self::WRITE_BATCH_SIZE) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                $this->insertBatch(
                    'ue_legacy_export_identity_lookup',
                    [
                        'file_id', 'export_index', 'identity_hash', 'path_hash_ci', 'object_term_id',
                        'class_package_term_id', 'class_name_term_id', 'outer_index', 'object_flags',
                    ],
                    $chunk
                );
                $sqlBatches++;
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return ['file_id' => $fileId, 'rows' => count($rows), 'sql_batches' => $sqlBatches];
    }

    /**
     * Compatibility entry point for callers that already hold container bytes.
     * Production publication uses writeVersionedMetadata() so it never needs a
     * full metadata-container PHP string merely to register size and SHA-256.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,int>|null $resolvedTermIds
     */
    public function writeVersioned(
        array $snapshot,
        string $storedBytes,
        int $uncompressedSize,
        int $formatVersion,
        int $codec,
        int &$sqlBatches,
        ?array $resolvedTermIds = null
    ): void {
        $this->writeVersionedMetadata(
            $snapshot,
            strlen($storedBytes),
            hash('sha256', $storedBytes, true),
            $uncompressedSize,
            $formatVersion,
            $codec,
            $sqlBatches,
            $resolvedTermIds
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,int>|null $resolvedTermIds
     */
    public function writeVersionedMetadata(
        array $snapshot,
        int $compressedSize,
        string $payloadSha256,
        int $uncompressedSize,
        int $formatVersion,
        int $codec,
        int &$sqlBatches,
        ?array $resolvedTermIds = null
    ): void {
        if ($compressedSize < 1 || strlen($payloadSha256) !== 32) {
            throw new RuntimeException('Compact metadata registration requires a valid size and binary SHA-256.');
        }

        $file = (array)$snapshot['file'];
        $imports = (array)$snapshot['imports'];
        $exports = (array)$snapshot['exports'];
        $dependencies = (array)$snapshot['dependencies'];
        $paths = (array)$snapshot['paths'];
        $fileId = (int)$file['id'];
        $resolutionLabels = $this->dependencyResolutionLabels($dependencies);

        $importsByIndex = [];
        foreach ($imports as $row) {
            if (is_array($row)) {
                $importsByIndex[(int)$row['import_index']] = $row;
            }
        }

        $termIds = $resolvedTermIds
            ?? $this->resolveTermIds($this->snapshotTermValues($snapshot), $sqlBatches);

        $this->db->prepare('DELETE FROM ue_export_lookup WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_export_path_lookup WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_legacy_export_identity_lookup WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_dependency_links WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_dependency_identity_lookup WHERE file_id=?')->execute([$fileId]);
        $sqlBatches += 5;

        $exportColumns = [
            'file_id', 'export_index', 'object_term_id', 'class_term_id',
            'path_hash', 'local_path_term_id',
        ];
        $exportPathColumns = [
            'file_id', 'export_index', 'path_hash_ci', 'local_path_term_id', 'class_term_id',
            'class_package_term_id', 'class_name_term_id', 'object_flags', 'outer_index',
        ];
        $importsByIdentityIndex = [];
        foreach ($imports as $identityImport) {
            if (is_array($identityImport)) {
                $importsByIdentityIndex[(int)($identityImport['import_index'] ?? 0)] = $identityImport;
            }
        }
        $exportsByIdentityIndex = [];
        foreach ($exports as $identityExport) {
            if (is_array($identityExport)) {
                $exportsByIdentityIndex[(int)($identityExport['export_index'] ?? 0)] = $identityExport;
            }
        }
        $packageName = trim((string)($file['package_name'] ?? ''));
        $engineRow = \catalog_one(
            $this->db,
            'SELECT p.engine_key FROM ue_games g'
            . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
            . ' WHERE g.id=? LIMIT 1',
            [(int)($file['game_id'] ?? 0)]
        );
        $engineKey = strtoupper(trim((string)($engineRow['engine_key'] ?? '')));
        $exportRows = [];
        $exportPathRows = [];
        foreach ($exports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['export_index'];
            $object = (string)$row['object_name'];
            $class = trim((string)($row['class_name'] ?? ''));
            $localPath = (string)($paths['exports'][$index]['local'] ?? '');
            $classTermId = $class !== '' ? $this->requiredTermId($termIds, $class) : null;
            $localPathTermId = $this->requiredTermId($termIds, $localPath);
            $exportRows[] = [
                $fileId,
                $index,
                $this->requiredTermId($termIds, $object),
                $classTermId,
                md5($localPath, true),
                $localPathTermId,
            ];
            [$verifyClassPackage, $verifyClassName] = $engineKey === 'UE3'
                ? CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
                    $row,
                    $importsByIdentityIndex,
                    $exportsByIdentityIndex,
                    $packageName
                )
                : CatalogCompactIdentityEnricher::legacyExportClassIdentity(
                    $row,
                    $importsByIdentityIndex,
                    $exportsByIdentityIndex,
                    $packageName
                );
            $exportPathRows[] = [
                $fileId,
                $index,
                CatalogUnrealIdentityHash::objectPathBinary($localPath),
                $localPathTermId,
                $classTermId,
                $verifyClassPackage !== '' ? $this->requiredTermId($termIds, $verifyClassPackage) : null,
                $verifyClassName !== '' ? $this->requiredTermId($termIds, $verifyClassName) : null,
                isset($row['object_flags']) ? (int)$row['object_flags'] : null,
                isset($row['outer_index']) ? (int)$row['outer_index'] : null,
            ];
            if (count($exportRows) >= self::WRITE_BATCH_SIZE) {
                $this->insertBatch('ue_export_lookup', $exportColumns, $exportRows);
                $this->insertBatch('ue_export_path_lookup', $exportPathColumns, $exportPathRows);
                $sqlBatches += 2;
                $exportRows = [];
                $exportPathRows = [];
            }
        }
        if ($exportRows !== []) {
            $this->insertBatch('ue_export_lookup', $exportColumns, $exportRows);
            $this->insertBatch('ue_export_path_lookup', $exportPathColumns, $exportPathRows);
            $sqlBatches += 2;
        }

        if ($this->isLegacyVerifyImportGame((int)($file['game_id'] ?? 0))) {
            $legacyRows = $this->legacyProjectionRows($snapshot, $termIds);
            foreach (array_chunk($legacyRows, self::WRITE_BATCH_SIZE) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                $this->insertBatch(
                    'ue_legacy_export_identity_lookup',
                    [
                        'file_id', 'export_index', 'identity_hash', 'path_hash_ci', 'object_term_id',
                        'class_package_term_id', 'class_name_term_id', 'outer_index', 'object_flags',
                    ],
                    $chunk
                );
                $sqlBatches++;
            }
        }

        $dependencyColumns = [
            'file_id', 'import_index', 'required_package_term_id', 'required_path_hash',
            'required_object_term_id', 'import_class_package_term_id', 'import_class_name_term_id',
            'import_object_term_id', 'resolved_file_id', 'resolved_export_index', 'status', 'resolution_source',
            'resolution_confidence', 'resolution_source_term_id', 'resolution_confidence_term_id',
        ];
        $dependencyIdentityColumns = [
            'file_id', 'import_index', 'required_package_term_id',
            'verify_identity_hash', 'required_path_hash_ci',
        ];
        $dependencyRows = [];
        $dependencyIdentityRows = [];
        foreach ($dependencies as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['import_index'];
            $labels = $resolutionLabels[$index] ?? null;
            $import = $importsByIndex[$index] ?? null;
            if (!is_array($labels)) {
                throw new RuntimeException('Missing dependency resolution labels for import index ' . $index . '.');
            }
            if (!is_array($import)) {
                throw new RuntimeException('Missing import metadata for dependency import index ' . $index . '.');
            }
            [$status, $sourceCode, $confidenceCode] = CompressedMetadataLegacySnapshot::dependencyCodes(
                strtolower(trim((string)$row['status']))
            );
            $source = (string)$labels['source'];
            $confidence = (string)$labels['confidence'];
            $requiredObject = (string)$row['required_object_path'];
            $classPackage = trim((string)($import['class_package'] ?? ''));
            $className = trim((string)($import['class_name'] ?? ''));
            $requiredPackageTermId = $this->requiredTermId($termIds, (string)$row['required_package']);
            $relativePath = (string)$paths['imports'][$index]['relative'];
            $verifyIdentityHash = (($import['verify_identity_hash'] ?? '') !== '')
                ? hex2bin((string)$import['verify_identity_hash'])
                : null;
            $requiredPathHashCi = (($import['path_hash_ci'] ?? '') !== '')
                ? hex2bin((string)$import['path_hash_ci'])
                : CatalogUnrealIdentityHash::objectPathBinary($relativePath);

            $dependencyRows[] = [
                $fileId,
                $index,
                $requiredPackageTermId,
                md5($relativePath, true),
                $this->requiredTermId($termIds, $requiredObject),
                $classPackage !== '' ? $this->requiredTermId($termIds, $classPackage) : null,
                $className !== '' ? $this->requiredTermId($termIds, $className) : null,
                trim((string)($import['object_name'] ?? '')) !== ''
                    ? $this->requiredTermId($termIds, trim((string)$import['object_name']))
                    : null,
                $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                $status,
                $sourceCode,
                $confidenceCode,
                $this->requiredTermId($termIds, $source),
                $this->requiredTermId($termIds, $confidence),
            ];
            $dependencyIdentityRows[] = [
                $fileId,
                $index,
                $requiredPackageTermId,
                $verifyIdentityHash,
                $requiredPathHashCi,
            ];
            if (count($dependencyRows) >= self::WRITE_BATCH_SIZE) {
                $this->insertBatch('ue_dependency_links', $dependencyColumns, $dependencyRows);
                $this->insertBatch(
                    'ue_dependency_identity_lookup',
                    $dependencyIdentityColumns,
                    $dependencyIdentityRows
                );
                $sqlBatches += 2;
                $dependencyRows = [];
                $dependencyIdentityRows = [];
            }
        }
        if ($dependencyRows !== []) {
            $this->insertBatch('ue_dependency_links', $dependencyColumns, $dependencyRows);
            $this->insertBatch(
                'ue_dependency_identity_lookup',
                $dependencyIdentityColumns,
                $dependencyIdentityRows
            );
            $sqlBatches += 2;
        }

        $timestamp = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO ue_file_metadata('
            . 'file_id,format_version,codec,compressed_size,uncompressed_size,payload_sha256,'
            . 'name_count,import_count,export_count,created_at,updated_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'format_version=VALUES(format_version),codec=VALUES(codec),'
            . 'compressed_size=VALUES(compressed_size),uncompressed_size=VALUES(uncompressed_size),'
            . 'payload_sha256=VALUES(payload_sha256),name_count=VALUES(name_count),'
            . 'import_count=VALUES(import_count),export_count=VALUES(export_count),'
            . 'updated_at=VALUES(updated_at)'
        );
        $statement->execute([
            $fileId,
            $formatVersion,
            $codec,
            $compressedSize,
            $uncompressedSize,
            $payloadSha256,
            count((array)$snapshot['names']),
            count((array)$snapshot['imports']),
            count((array)$snapshot['exports']),
            $timestamp,
            $timestamp,
        ]);
        $sqlBatches++;
    }

    /** @param array<int,mixed> $dependencies @return array<int,array{source:string,confidence:string}> */
    private function dependencyResolutionLabels(array $dependencies): array
    {
        $labels = [];
        foreach ($dependencies as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Dependency snapshot contains a non-row value.');
            }
            $index = (int)($row['import_index'] ?? -1);
            $source = trim((string)($row['resolution_source'] ?? ''));
            $confidence = trim((string)($row['resolution_confidence'] ?? ''));
            if ($index < 0 || $source === '' || $confidence === '') {
                throw new RuntimeException(
                    'Current compact dependency snapshot is missing resolution labels for import index ' . $index . '.'
                );
            }
            $labels[$index] = ['source' => $source, 'confidence' => $confidence];
        }
        if (count($labels) !== count($dependencies)) {
            throw new RuntimeException('Current compact dependency snapshot contains duplicate import indexes.');
        }
        return $labels;
    }

    /** @param array<string,mixed> $snapshot @return \Generator<int,string> */
    private function snapshotTermValues(array $snapshot): \Generator
    {
        $paths = (array)($snapshot['paths'] ?? []);
        $file = (array)($snapshot['file'] ?? []);
        if (trim((string)($file['package_name'] ?? '')) !== '') {
            yield trim((string)$file['package_name']);
        }

        foreach ((array)($snapshot['names'] ?? []) as $row) {
            if (is_array($row)) {
                yield (string)($row['name_text'] ?? '');
            }
        }

        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)($row['export_index'] ?? -1);
            $objectName = (string)($row['object_name'] ?? '');
            yield $objectName;
            $trimmedObjectName = trim($objectName);
            if ($trimmedObjectName !== $objectName) {
                yield $trimmedObjectName;
            }
            yield (string)($index >= 0 ? ($paths['exports'][$index]['local'] ?? '') : '');
            $className = trim((string)($row['class_name'] ?? ''));
            if ($className !== '') {
                yield $className;
            }
            // UE1/UE2 VerifyImport uses GetExportClassPackage/GetExportClassName,
            // not the generic export class label above. v4 enrichment persists
            // those exact derived identities (including the ClassIndex==0
            // Core.Class case), so prime every value that the legacy projection
            // can subsequently require inside its publication transaction.
            $verifyClassPackage = trim((string)($row['verify_class_package'] ?? ''));
            $verifyClassName = trim((string)($row['verify_class_name'] ?? ''));
            if ($verifyClassPackage !== '') {
                yield $verifyClassPackage;
            }
            if ($verifyClassName !== '') {
                yield $verifyClassName;
            }
        }

        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $objectName = (string)($row['object_name'] ?? '');
            yield $objectName;
            $trimmedObjectName = trim($objectName);
            if ($trimmedObjectName !== $objectName) {
                yield $trimmedObjectName;
            }
            $classPackage = trim((string)($row['class_package'] ?? ''));
            $className = trim((string)($row['class_name'] ?? ''));
            if ($classPackage !== '') {
                yield $classPackage;
            }
            if ($className !== '') {
                yield $className;
            }
        }

        foreach ((array)($snapshot['dependencies'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            yield (string)($row['required_package'] ?? '');
            yield (string)($row['required_object_path'] ?? '');
            yield (string)($row['resolution_source'] ?? '');
            yield (string)($row['resolution_confidence'] ?? '');
        }
    }

    /** @param iterable<string> $values @return array<string,int> */
    private function resolveTermIds(iterable $values, int &$sqlBatches): array
    {
        $terms = [];
        foreach ($values as $value) {
            $length = strlen($value);
            if ($length > 65535) {
                throw new RuntimeException('Compact lookup term exceeds 65,535 bytes.');
            }
            $key = $this->termKey($value);
            if (isset($terms[$key]) && !hash_equals((string)$terms[$key]['value'], $value)) {
                throw new RuntimeException('Compact lookup term hash collision detected inside conversion batch.');
            }
            if (!isset($terms[$key])) {
                $terms[$key] = [
                    'value' => $value,
                    'hash' => md5($value, true),
                    'length' => $length,
                    'prefix' => substr($value, 0, 200),
                    'overflow' => $length > 200 ? 1 : 0,
                ];
            }
        }
        if ($terms === []) {
            return [];
        }

        ksort($terms, SORT_STRING);

        // Resolve the shared dictionary before attempting any INSERT. The old
        // INSERT IGNORE-first path submitted every term on every metadata rebuild,
        // including terms that already existed. InnoDB still reserves AUTO_INCREMENT
        // values for ignored duplicate rows, so large dependency refreshes could
        // burn billions of ue_terms IDs without creating billions of terms.
        $resolved = [];
        $this->resolveTermSet($terms, $terms, $resolved, $sqlBatches);

        if (!$this->db->inTransaction() && count($resolved) !== count($terms)) {
            $missing = array_filter(
                $terms,
                static fn(array $term, string $key): bool => !isset($resolved[$key]),
                ARRAY_FILTER_USE_BOTH
            );

            $chunk = [];
            foreach ($missing as $term) {
                $chunk[] = $term;
                if (count($chunk) >= self::TERM_BATCH_SIZE) {
                    $this->insertTermBatch($chunk);
                    $sqlBatches++;
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->insertTermBatch($chunk);
                $sqlBatches++;
            }

            // Concurrent workers may race the same genuinely new term. INSERT
            // IGNORE remains appropriate for that small race window; resolve the
            // missing subset afterwards to obtain whichever stable IDs won.
            $this->resolveTermSet($missing, $terms, $resolved, $sqlBatches);
        }

        if (count($resolved) !== count($terms)) {
            throw new RuntimeException(
                'Could not resolve all compact lookup terms: expected ' . count($terms)
                . ', resolved ' . count($resolved) . '.'
            );
        }
        return $resolved;
    }

    /**
     * @param array<string,array{value:string,hash:string,length:int,prefix:string,overflow:int}> $subset
     * @param array<string,array{value:string,hash:string,length:int,prefix:string,overflow:int}> $allTerms
     * @param array<string,int> $resolved
     */
    private function resolveTermSet(array $subset, array $allTerms, array &$resolved, int &$sqlBatches): void
    {
        $chunk = [];
        foreach ($subset as $term) {
            $chunk[] = $term;
            if (count($chunk) >= self::TERM_BATCH_SIZE) {
                $this->resolveTermBatch($chunk, $allTerms, $resolved);
                $sqlBatches++;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->resolveTermBatch($chunk, $allTerms, $resolved);
            $sqlBatches++;
        }
    }

    /** @param list<array{value:string,hash:string,length:int,prefix:string,overflow:int}> $chunk */
    private function insertTermBatch(array $chunk): void
    {
        $placeholders = [];
        $arguments = [];
        foreach ($chunk as $term) {
            $placeholders[] = '(?,?,?,?)';
            array_push($arguments, $term['hash'], $term['length'], $term['prefix'], $term['overflow']);
        }
        $this->executeWithContentionRetry(
            'INSERT IGNORE INTO ue_terms(value_hash,value_length,value_prefix,is_overflow) VALUES '
                . implode(',', $placeholders),
            $arguments
        );
    }

    /**
     * @param list<array{value:string,hash:string,length:int,prefix:string,overflow:int}> $chunk
     * @param array<string,array{value:string,hash:string,length:int,prefix:string,overflow:int}> $terms
     * @param array<string,int> $resolved
     */
    private function resolveTermBatch(array $chunk, array $terms, array &$resolved): void
    {
        $predicates = [];
        $arguments = [];
        foreach ($chunk as $term) {
            $predicates[] = '(value_hash=? AND value_length=?)';
            $arguments[] = $term['hash'];
            $arguments[] = $term['length'];
        }
        $statement = $this->db->prepare(
            'SELECT id,value_hash,value_length,value_prefix,is_overflow FROM ue_terms WHERE '
            . implode(' OR ', $predicates)
        );
        $statement->execute($arguments);
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $key = bin2hex((string)$row['value_hash']) . ':' . (int)$row['value_length'];
            $expected = $terms[$key] ?? null;
            if (!is_array($expected)) {
                continue;
            }
            $stored = (string)$row['value_prefix'];
            $expectedPrefix = (string)$expected['prefix'];
            $matches = (int)$row['is_overflow'] === 1
                ? str_starts_with($stored, $expectedPrefix)
                : hash_equals($stored, $expectedPrefix);
            if (!$matches || (int)$row['is_overflow'] !== (int)$expected['overflow']) {
                throw new RuntimeException('Compact lookup term hash collision or stored-prefix mismatch.');
            }
            $resolved[$key] = (int)$row['id'];
        }
    }

    /** @param list<mixed> $arguments */
    private function executeWithContentionRetry(string $sql, array $arguments): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $statement = $this->db->prepare($sql);
                $statement->execute($arguments);
                return;
            } catch (Throwable $error) {
                $mysqlCode = is_array($error instanceof \PDOException ? $error->errorInfo : null)
                    ? (int)($error->errorInfo[1] ?? 0)
                    : 0;
                if ($mysqlCode === 1467
                    || str_contains(strtolower($error->getMessage()), 'failed to read auto-increment value from storage engine')) {
                    throw new RuntimeException(
                        'ue_terms AUTO_INCREMENT cannot allocate another term ID. '
                        . 'Deploy the compact-term dictionary fix, stop workers, then run '
                        . 'php catalog/bin/repair-ue-terms-auto-increment.php --apply.',
                        0,
                        $error
                    );
                }
                if (!PdoContention::retryable($error) || $attempt >= self::TERM_CONTENTION_ATTEMPTS) {
                    throw $error;
                }
                usleep(PdoContention::backoffMicros($attempt, 10000));
            }
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,int> $termIds
     * @return list<list<mixed>>
     */
    private function legacyProjectionRows(array $snapshot, array $termIds): array
    {
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $imports = (array)($snapshot['imports'] ?? []);
        $exports = (array)($snapshot['exports'] ?? []);

        $importsByIndex = [];
        foreach ($imports as $fallback => $row) {
            if (is_array($row)) {
                $importsByIndex[isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback] = $row;
            }
        }
        $exportsByIndex = [];
        foreach ($exports as $fallback => $row) {
            if (is_array($row)) {
                $exportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
            }
        }

        $rows = [];
        foreach ($exportsByIndex as $index => $row) {
            $classPackage = trim((string)($row['verify_class_package'] ?? ''));
            $className = trim((string)($row['verify_class_name'] ?? ''));
            if ($classPackage === '' || $className === '') {
                [$classPackage, $className] = $this->legacyExportClassIdentity(
                    $row,
                    $importsByIndex,
                    $exportsByIndex,
                    (string)($file['package_name'] ?? '')
                );
            }
            $objectName = trim((string)($row['object_name'] ?? ''));
            if ($objectName === '' || $classPackage === '' || $className === '') {
                continue;
            }
            $rows[] = [
                $fileId,
                (int)$index,
                (($row['verify_identity_hash'] ?? '') !== '')
                    ? hex2bin((string)$row['verify_identity_hash'])
                    : CatalogUnrealIdentityHash::verifyImportBinary($objectName, $className, $classPackage),
                (($row['path_hash_ci'] ?? '') !== '')
                    ? hex2bin((string)$row['path_hash_ci'])
                    : CatalogUnrealIdentityHash::objectPathBinary((string)($row['local_path'] ?? '')),
                $this->requiredTermId($termIds, $objectName),
                $this->requiredTermId($termIds, $classPackage),
                $this->requiredTermId($termIds, $className),
                (int)($row['outer_index'] ?? 0),
                (int)($row['object_flags'] ?? 0),
            ];
        }
        return $rows;
    }

    private function isLegacyVerifyImportGame(int $gameId): bool
    {
        if ($gameId < 1) {
            return false;
        }
        $statement = $this->db->prepare(
            'SELECT UPPER(TRIM(COALESCE(p.engine_key,""))) engine_key'
            . ' FROM ue_games g'
            . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
            . ' WHERE g.id=? LIMIT 1'
        );
        $statement->execute([$gameId]);
        return in_array((string)$statement->fetchColumn(), ['UE1', 'UE2'], true);
    }

    /**
     * Mirrors UE1/UE2 ULinkerLoad::GetExportClassName/GetExportClassPackage.
     *
     * @param array<string,mixed> $export
     * @param array<int,array<string,mixed>> $imports
     * @param array<int,array<string,mixed>> $exports
     * @return array{0:string,1:string}
     */
    private function legacyExportClassIdentity(
        array $export,
        array $imports,
        array $exports,
        string $packageName
    ): array {
        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex < 0) {
            $classImport = $imports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) {
                return ['', ''];
            }
            $className = trim((string)($classImport['object_name'] ?? ''));
            $outerIndex = (int)($classImport['outer_index'] ?? 0);
            if ($outerIndex >= 0) {
                return ['', $className];
            }
            $classPackageImport = $imports[-$outerIndex - 1] ?? null;
            return [
                is_array($classPackageImport) ? trim((string)($classPackageImport['object_name'] ?? '')) : '',
                $className,
            ];
        }
        if ($classIndex > 0) {
            $classExport = $exports[$classIndex - 1] ?? null;
            return [
                trim($packageName),
                is_array($classExport) ? trim((string)($classExport['object_name'] ?? '')) : '',
            ];
        }
        return ['Core', 'Class'];
    }

    /** @param array<string,int> $termIds */
    private function requiredTermId(array $termIds, string $value): int
    {
        $key = $this->termKey($value);
        if (!isset($termIds[$key])) {
            throw new RuntimeException(
                'Compact lookup term was not resolved before projection publication: '
                . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        return (int)$termIds[$key];
    }

    private function termKey(string $value): string
    {
        return md5($value) . ':' . strlen($value);
    }

    /** @param list<string> $columns @param list<list<mixed>> $rows */
    private function insertBatch(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (count($rows) > self::WRITE_BATCH_SIZE) {
            throw new RuntimeException('Compact lookup insert batch exceeded the bounded row limit.');
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('Invalid compact lookup table name.');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                throw new RuntimeException('Invalid compact lookup column name.');
            }
        }

        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $statement = $this->db->prepare(
            'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($rows), $rowPlaceholder))
        );
        $arguments = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $arguments[] = $value;
            }
        }
        $statement->execute($arguments);
    }
}
