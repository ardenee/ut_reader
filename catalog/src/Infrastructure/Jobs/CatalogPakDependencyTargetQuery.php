<?php
/**
 * Discovers only dependency owners affected by packages introduced by one PAK.
 *
 * PAK imports already publish compact dependency rows for each package as it is
 * parsed. Once all entries are present, only the files imported/aliased by that
 * PAK plus existing files that reference one of those provider package names
 * need to be re-resolved. A whole-game dependency rebuild is unnecessary.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

final class CatalogPakDependencyTargetQuery
{
    private const FILE_BATCH_SIZE = 500;
    private const PACKAGE_BATCH_SIZE = 200;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *   source_file_ids:list<int>,
     *   provider_packages:list<string>,
     *   affected_file_ids:list<int>,
     *   target_file_ids:list<int>
     * }
     */
    public function discover(int $parentJobId, int $gameId): array
    {
        $sourceFileIds = $this->sourceFileIds($parentJobId, $gameId);
        if ($sourceFileIds === []) {
            return [
                'source_file_ids' => [],
                'provider_packages' => [],
                'affected_file_ids' => [],
                'target_file_ids' => [],
            ];
        }

        $packageNames = $this->providerPackageNames($sourceFileIds, $gameId);
        $affectedFileIds = $this->affectedFileIds($packageNames, $gameId);

        $targets = [];
        foreach (array_merge($sourceFileIds, $affectedFileIds) as $fileId) {
            $fileId = (int)$fileId;
            if ($fileId > 0) {
                $targets[$fileId] = true;
            }
        }

        $targetFileIds = array_map('intval', array_keys($targets));
        sort($targetFileIds, SORT_NUMERIC);

        return [
            'source_file_ids' => $sourceFileIds,
            'provider_packages' => $packageNames,
            'affected_file_ids' => $affectedFileIds,
            'target_file_ids' => $targetFileIds,
        ];
    }

    /** @return list<int> */
    private function sourceFileIds(int $parentJobId, int $gameId): array
    {
        $ids = [];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key LIKE "pak-entry:%" '
            . 'AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }
            $outcome = (string)($result['outcome'] ?? '');
            if (!in_array($outcome, ['imported', 'alias'], true)) {
                continue;
            }
            $fileId = (int)($result['file_id'] ?? 0);
            if ($fileId > 0) {
                $ids[$fileId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        // Guard against stale/foreign IDs in a recovered result payload.
        $verified = [];
        foreach (array_chunk(array_map('intval', array_keys($ids)), self::FILE_BATCH_SIZE) as $chunk) {
            $statement = $this->db->prepare(
                'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $statement->execute(array_merge([$gameId], $chunk));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fileId) {
                $verified[(int)$fileId] = true;
            }
        }
        $result = array_map('intval', array_keys($verified));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param list<int> $fileIds @return list<string> */
    private function providerPackageNames(array $fileIds, int $gameId): array
    {
        $names = [];
        foreach (array_chunk($fileIds, self::FILE_BATCH_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $primary = $this->db->prepare(
                'SELECT package_name FROM ue_files WHERE game_id=? AND scan_status="verified" '
                . 'AND id IN (' . $placeholders . ')'
            );
            $primary->execute(array_merge([$gameId], $chunk));
            foreach ($primary->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                $this->collectPackageName($names, (string)$name);
            }

            $aliases = $this->db->prepare(
                'SELECT a.package_name FROM ue_file_package_aliases a '
                . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                . 'WHERE a.game_id=? AND f.scan_status="verified" '
                . 'AND a.file_id IN (' . $placeholders . ')'
            );
            $aliases->execute(array_merge([$gameId], $chunk));
            foreach ($aliases->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                $this->collectPackageName($names, (string)$name);
            }
        }

        ksort($names, SORT_STRING);
        return array_values($names);
    }

    /** @param array<string,string> $names */
    private function collectPackageName(array &$names, string $name): void
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 255) {
            return;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $names[$key] ??= $name;
    }

    /** @param list<string> $packageNames @return list<int> */
    private function affectedFileIds(array $packageNames, int $gameId): array
    {
        if ($packageNames === []) {
            return [];
        }
        $ids = [];
        foreach (array_chunk($packageNames, self::PACKAGE_BATCH_SIZE) as $chunk) {
            $predicates = [];
            $arguments = [$gameId];
            foreach ($chunk as $name) {
                foreach ([
                    Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,
                    Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,
                ] as $kind) {
                    $predicates[] = '(p.package_key_kind=? AND p.package_key=?)';
                    $arguments[] = $kind;
                    $arguments[] = Uedb5SqlProjectionContract::classicPackageKeyBinary($name, $kind);
                }
            }
            if ($predicates === []) {
                continue;
            }
            $statement = $this->db->prepare(
                'SELECT DISTINCT p.file_id FROM ue_uedb5_dependency_packages p '
                . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
                . 'WHERE p.game_id=? AND f.scan_status="verified" AND ('
                . implode(' OR ', $predicates) . ')'
            );
            $statement->execute($arguments);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fileId) {
                $ids[(int)$fileId] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);
        return $result;
    }

}
