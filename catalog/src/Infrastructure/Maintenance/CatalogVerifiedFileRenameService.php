<?php
/**
 * Corrects the logical filename/package identity of an already verified file.
 *
 * The physical stored object is intentionally left in place. `stored_name` and
 * `relative_path` are storage identities. `original_name`, `package_name` and
 * the ue_files source-relative identity are corrected together so a later
 * maintenance reimport cannot restore a historical cleanup mistake.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5VerifiedFilePublisher;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogVerifiedFileRenameService
{
    private const MAX_SUGGESTION_EXPORT_TERMS = 500;
    private const MAX_SUGGESTION_DEPENDENCY_ROWS = 5000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @return array{
     *   changed:bool,file_id:int,game_id:int,old_original_name:string,new_original_name:string,
     *   old_package_name:string,new_package_name:string,dependency_job_id:int
     * }
     */
    public function rename(int $fileId, string $newOriginalName, ?int $userId = null): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A valid file ID is required.');
        }
        $newOriginalName = $this->validatedFilename($newOriginalName);

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT id,game_id,package_name,original_name,source_relative_path,extension,scan_status '
                . 'FROM ue_files WHERE id=? FOR UPDATE'
            );
            $statement->execute([$fileId]);
            $file = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($file) || (string)$file['scan_status'] !== 'verified') {
                throw new RuntimeException('Only verified catalogue files can be renamed here.');
            }

            $oldOriginalName = (string)$file['original_name'];
            $oldPackageName = (string)$file['package_name'];
            $gameId = (int)$file['game_id'];
            $oldExtension = strtolower((string)$file['extension']);
            $newExtension = strtolower((string)pathinfo($newOriginalName, PATHINFO_EXTENSION));
            if ($newExtension === '' || $newExtension !== $oldExtension) {
                throw new RuntimeException(
                    'The corrected filename must keep the existing .' . ($oldExtension !== '' ? $oldExtension : '(none)') . ' extension.'
                );
            }

            $newPackageName = $this->correctedPackageName($oldPackageName, $newOriginalName);
            if ($newPackageName === '' || $newPackageName === '.' || $newPackageName === '..') {
                throw new RuntimeException('The corrected filename does not contain a valid package name.');
            }

            if ($newOriginalName === $oldOriginalName && $newPackageName === $oldPackageName) {
                $this->db->commit();
                return [
                    'changed' => false,
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'old_original_name' => $oldOriginalName,
                    'new_original_name' => $newOriginalName,
                    'old_package_name' => $oldPackageName,
                    'new_package_name' => $newPackageName,
                    'dependency_job_id' => 0,
                ];
            }

            $sourceRelativePath = $this->correctedSourceRelativePath(
                (string)($file['source_relative_path'] ?? ''),
                $newOriginalName
            );
            $update = $this->db->prepare(
                'UPDATE ue_files SET original_name=?,package_name=?,source_relative_path=? '
                . 'WHERE id=? AND scan_status="verified"'
            );
            $update->execute([$newOriginalName, $newPackageName, $sourceRelativePath, $fileId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The verified file identity changed before the rename could be saved.');
            }

            // An alias that exactly duplicated the old canonical identity is
            // stale after an explicit correction. Do not preserve a known-bad
            // cleanup-derived package name as a dependency provider alias.
            $deleteAlias = $this->db->prepare(
                'DELETE FROM ue_file_package_aliases '
                . 'WHERE file_id=? AND game_id=? AND package_name=? AND original_name=?'
            );
            $deleteAlias->execute([$fileId, $gameId, $oldPackageName, $oldOriginalName]);

            // Always create a post-rename dependency pass with its own active
            // dedupe identity. Reusing the ordinary rebuild-file key is unsafe:
            // an older rebuild may already be running and could have read the old
            // package name before this transaction commits. This fresh unit will
            // reconcile the corrected provider, rebuild this file, then enqueue
            // the rename-aware affected-file chain.
            $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
            $dependencyJobId = (new PdoJobQueue($this->db))->enqueue(
                $queueName,
                JobType::REBUILD_FILE_DEPENDENCIES,
                [
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'package_name' => $newPackageName,
                    'post_import' => true,
                    'rename_refresh' => true,
                    'old_package_name' => $oldPackageName,
                ],
                15,
                null,
                'rename-file-dependencies:' . $fileId . ':'
                    . substr(hash('sha256', $oldOriginalName . "\0" . $newOriginalName), 0, 32),
                $userId,
                3
            );
            if ($dependencyJobId < 1) {
                throw new RuntimeException('The filename correction could not queue its dependency refresh.');
            }

            $this->db->commit();
            return [
                'changed' => true,
                'file_id' => $fileId,
                'game_id' => $gameId,
                'old_original_name' => $oldOriginalName,
                'new_original_name' => $newOriginalName,
                'old_package_name' => $oldPackageName,
                'new_package_name' => $newPackageName,
                'dependency_job_id' => $dependencyJobId,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Find package names referenced by unresolved dependencies whose imported
     * object names are exported by this file. This is deliberately bounded and
     * only intended as an admin hint for spotting historical filename cleanup.
     *
     * @return list<array{package_name:string,suggested_filename:string,matched_objects:int,referencing_files:int}>
     */
    public function possiblePackageNames(int $fileId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 20));
        $file = $this->one(
            'SELECT id,game_id,package_name,extension FROM ue_files WHERE id=? AND scan_status="verified"',
            [$fileId]
        );
        if ($file === null) {
            return [];
        }

        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 rename suggestions.');
        }
        $gameId = (int)$file['game_id'];
        $reader = new Uedb5MetadataReader($storageRoot);
        try {
            $snapshot = $reader->snapshot($gameId, $fileId);
            $exports = Uedb5ClassicDependencyResolver::exportCoverageRows($snapshot);
        } finally {
            $reader->clearCache($gameId, $fileId);
        }

        $objectKeys = [];
        foreach ($exports as $export) {
            $name = trim((string)($export['object_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized = CatalogUnrealIdentityHash::fnameKey($name);
            if ($normalized === '') {
                continue;
            }
            $objectKeys[$normalized] = md5($normalized, true);
            if (count($objectKeys) >= self::MAX_SUGGESTION_EXPORT_TERMS) {
                break;
            }
        }
        if ($objectKeys === []) {
            return [];
        }

        $predicates = [];
        $args = [$gameId, $fileId];
        foreach ($objectKeys as $binary) {
            $predicates[] = 'e.required_object_key=?';
            $args[] = $binary;
        }
        $statement = $this->db->prepare(
            'SELECT e.file_id,e.source_index,p.required_package_name '
            . 'FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_uedb5_dependency_packages p ON p.file_id=e.file_id '
            . 'AND p.package_key_kind=e.required_package_key_kind AND p.package_key=e.required_package_key '
            . 'JOIN ue_files f ON f.id=e.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'WHERE p.game_id=? AND e.file_id<>? AND e.source_kind=1 AND e.outcome=0 '
            . 'AND e.required_object_key_kind=' . Uedb5SqlProjectionContract::OBJECT_KEY_NAME . ' '
            . 'AND (' . implode(' OR ', $predicates) . ') '
            . 'ORDER BY e.file_id,e.source_index LIMIT ' . self::MAX_SUGGESTION_DEPENDENCY_ROWS
        );
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $byFile = [];
        foreach ($rows as $row) {
            $ownerId = (int)$row['file_id'];
            $index = (int)$row['source_index'];
            $byFile[$ownerId]['meta'][$index] = $row;
            $byFile[$ownerId]['indexes'][] = $index;
        }

        $dependencyReader = new Uedb5ParityV5ReadService($this->db, $this->config);
        $currentPackage = CatalogUnrealIdentityHash::fnameKey((string)$file['package_name']);
        $candidates = [];
        foreach ($byFile as $ownerId => $entry) {
            $details = $dependencyReader->dependenciesAtIndexes(
                $gameId,
                (int)$ownerId,
                array_values(array_unique((array)$entry['indexes']))
            );
            foreach ((array)$entry['meta'] as $index => $meta) {
                $detail = $details[(int)$index] ?? null;
                if (!is_array($detail) || (string)($detail['outcome'] ?? '') !== 'missing') {
                    continue;
                }
                $objectName = trim((string)($detail['required_object'] ?? ''));
                $objectKey = CatalogUnrealIdentityHash::fnameKey($objectName);
                if ($objectKey === '' || !isset($objectKeys[$objectKey])) {
                    continue;
                }

                $packageName = trim((string)($detail['required_package'] ?? $meta['required_package_name'] ?? ''));
                if ($packageName === ''
                    || str_contains($packageName, '/')
                    || str_contains($packageName, '\\')
                    || CatalogUnrealIdentityHash::fnameKey($packageName) === $currentPackage) {
                    continue;
                }
                $key = CatalogUnrealIdentityHash::fnameKey($packageName);
                $candidates[$key] ??= [
                    'package_name'=>$packageName,
                    'object_keys'=>[],
                    'files'=>[],
                ];
                $candidates[$key]['object_keys'][$objectKey] = true;
                $candidates[$key]['files'][(int)$ownerId] = true;
            }
        }

        $extension = strtolower((string)$file['extension']);
        $result = [];
        foreach ($candidates as $candidate) {
            $result[] = [
                'package_name'=>(string)$candidate['package_name'],
                'suggested_filename'=>(string)$candidate['package_name']
                    . ($extension !== '' ? '.' . $extension : ''),
                'matched_objects'=>count($candidate['object_keys']),
                'referencing_files'=>count($candidate['files']),
            ];
        }
        usort($result, static function (array $left, array $right): int {
            $objects = (int)$right['matched_objects'] <=> (int)$left['matched_objects'];
            if ($objects !== 0) {
                return $objects;
            }
            $files = (int)$right['referencing_files'] <=> (int)$left['referencing_files'];
            if ($files !== 0) {
                return $files;
            }
            return strcasecmp((string)$left['package_name'], (string)$right['package_name']);
        });
        return array_slice($result, 0, $limit);
    }

    private function correctedPackageName(string $currentPackageName, string $newOriginalName): string
    {
        $leaf = (string)pathinfo($newOriginalName, PATHINFO_FILENAME);
        if ($leaf === '') {
            return '';
        }
        $currentPackageName = trim(str_replace('\\', '/', $currentPackageName));
        if (!str_starts_with($currentPackageName, '/')) {
            return $leaf;
        }
        $separator = strrpos($currentPackageName, '/');
        if ($separator === false) {
            return '/' . $leaf;
        }
        return substr($currentPackageName, 0, $separator + 1) . $leaf;
    }

    private function correctedSourceRelativePath(string $current, string $newOriginalName): string
    {
        $current = trim(str_replace('\\', '/', $current), '/');
        if ($current === '') {
            return $newOriginalName;
        }
        $separator = strrpos($current, '/');
        return $separator === false
            ? $newOriginalName
            : substr($current, 0, $separator + 1) . $newOriginalName;
    }

    private function validatedFilename(string $filename): string
    {
        if ($filename === ''
            || trim($filename) !== $filename
            || str_contains($filename, "\0")
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $filename) === 1
            || $filename === '.'
            || $filename === '..') {
            throw new RuntimeException('Enter one filename only, without path separators or control characters.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($filename, 'UTF-8') : strlen($filename);
        if ($length > 255) {
            throw new RuntimeException('The corrected filename exceeds 255 characters.');
        }
        return $filename;
    }

    /** @param list<mixed> $arguments @return array<string,mixed>|null */
    private function one(string $sql, array $arguments): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
