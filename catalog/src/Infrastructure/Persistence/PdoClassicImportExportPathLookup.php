<?php
/**
 * Read-only, bounded navigation from a classic ImportMap row to actual ExportMap
 * records in verified, same-game catalogue package candidates.
 *
 * This is an exact *indexed path candidate*, NOT a source-engine VerifyImport
 * outcome or selection of the runtime-mounted provider. Ambiguities stay visible.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

final class PdoClassicImportExportPathLookup
{
    private const MAX_PROVIDER_FILES = 1200;
    private const MAX_SQL_CANDIDATES = 2500;
    private const QUERY_LEAVES_PER_BATCH = 120;
    private const MAX_MATCHES_PER_IMPORT = 24;

    /**
     * @param list<array<string,mixed>> $imports Original source-normalized import rows
     * @param array<string,list<int>> $providerFiles Exact same-game package identities, all physical file IDs retained
     * @return array<int,list<array{file_id:int,export_index:int,path:string}>>
     */
    public static function match(
        PDO $db,
        int $gameId,
        array $imports,
        array $providerFiles
    ): array {
        if ($gameId < 1 || $imports === [] || $providerFiles === []) return [];
        try {
            return self::matchReadOnly($db, $gameId, $imports, $providerFiles);
        } catch (Throwable $error) {
            // An unavailable accelerator must not stop a user examining genuine
            // stored ImportMap fields. A plain Full Path is the safe fallback.
            error_log('[UnrealDB exact export-path navigation] '.get_class($error)
                . ': '.$error->getMessage());
            return [];
        }
    }

    /** @return array<int,list<array{file_id:int,export_index:int,path:string}>> */
    private static function matchReadOnly(PDO $db, int $gameId, array $imports, array $providerFiles): array
    {
        $providerRootById = [];
        $requestMap = [];
        $md5Keys = [];
        $importsByIndex = [];

        foreach ($imports as $row) {
            $index = (int)($row['import_index'] ?? -1);
            $root = trim((string)($row['root_package'] ?? ''));
            $full = trim((string)($row['full_path'] ?? ''));
            $leaf = trim((string)($row['object_name'] ?? ''));
            $fileIds = $providerFiles[strtolower($root)] ?? [];
            // Top-level package imports do not represent an exported object
            // inside that same package. Their Root package link already exists.
            if ($index < 0 || $root === '' || $full === '' || $leaf === ''
                || (int)($row['outer_index'] ?? 0) === 0 || $fileIds === []
                || !empty($row['derived_path_has_numbered_fname'])) {
                continue;
            }
            $key = CatalogUnrealIdentityHash::nameKey($leaf);
            if ($key === '') continue;
            $sha = hash('sha256', $key);
            $md5Keys[md5($key)] = hex2bin(md5($key));
            $importsByIndex[$index] = $row;
            foreach ($fileIds as $id) {
                $id = (int)$id;
                if ($id < 1) continue;
                $providerRootById[$id] = $root;
                $requestMap[$id][$sha][$index] = true;
            }
        }

        if ($requestMap === [] || count($providerRootById) > self::MAX_PROVIDER_FILES) {
            return [];
        }
        $providerIds = array_keys($providerRootById);
        $parts = [];
        $totalCandidates = 0;
        foreach (array_chunk($providerIds, 240) as $fileChunk) {
            foreach (array_chunk(array_values($md5Keys), self::QUERY_LEAVES_PER_BATCH) as $nameChunk) {
                $fileSql = implode(',', array_fill(0, count($fileChunk), '?'));
                $nameSql = implode(',', array_fill(0, count($nameChunk), '?'));
                $stmt = $db->prepare(
                    'SELECT o.file_id,o.object_index,HEX(d.name_key_fingerprint) fingerprint '
                    . 'FROM ue_uedb5_compact_name_dictionary d '
                    . 'JOIN ue_uedb5_compact_object_candidates o ON o.name_id=d.name_id '
                    . 'JOIN ue_files f ON f.id=o.file_id AND f.game_id=? '
                    . 'AND f.scan_status="verified" '
                    . 'WHERE o.object_kind=1 AND o.file_id IN ('.$fileSql.') '
                    . 'AND d.name_key_hash IN ('.$nameSql.') '
                    . 'ORDER BY o.file_id,o.object_index LIMIT '.(self::MAX_SQL_CANDIDATES + 1)
                );
                $stmt->execute(array_merge([$gameId], $fileChunk, $nameChunk));
                while (($hit = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $fileId = (int)$hit['file_id'];
                    $index = (int)$hit['object_index'];
                    $fingerprint = strtolower((string)$hit['fingerprint']);
                    if (!isset($requestMap[$fileId][$fingerprint])) continue;
                    if (!isset($parts[$fileId][$index])) ++$totalCandidates;
                    $parts[$fileId][$index] = $fingerprint;
                    if ($totalCandidates > self::MAX_SQL_CANDIDATES) return [];
                }
                if (count($parts) > self::MAX_PROVIDER_FILES) return [];
            }
        }
        if ($parts === []) return [];

        $matches = [];
        foreach ($parts as $providerId => $indexToFingerprints) {
            $providerId = (int)$providerId;
            $root = (string)$providerRootById[$providerId];
            try {
                $actual = PdoPackageTablePageQuery::exportRowsByOriginalIndex(
                    $db, $providerId, array_keys($indexToFingerprints), $root
                );
            } catch (Throwable $error) {
                error_log('[UnrealDB export path lookup] Provider #' . $providerId
                    . ' unreadable: ' . $error->getMessage());
                continue;
            }
            foreach ($indexToFingerprints as $exportIndex => $fingerprint) {
                $source = $actual[(int)$exportIndex] ?? null;
                if (!is_array($source) || !empty($source['derived_path_has_numbered_fname'])) {
                    continue;
                }
                $actualPath = (string)($source['full_path'] ?? '');
                if ($actualPath === '') continue;
                $actualKey = CatalogUnrealIdentityHash::fnameKey($actualPath);
                foreach (array_keys($requestMap[$providerId][$fingerprint]) as $importIndex) {
                    $wanted = $importsByIndex[(int)$importIndex] ?? null;
                    if (!is_array($wanted)
                        || CatalogUnrealIdentityHash::fnameKey((string)($wanted['full_path'] ?? ''))
                            !== $actualKey) {
                        continue;
                    }
                    $matches[(int)$importIndex][] = [
                        'file_id' => $providerId,
                        'export_index' => (int)$exportIndex,
                        'path' => $actualPath,
                    ];
                }
            }
        }
        foreach ($matches as &$items) {
            usort($items, static fn(array $a, array $b): int =>
                [$a['file_id'], $a['export_index']] <=> [$b['file_id'], $b['export_index']]);
            if (count($items) > self::MAX_MATCHES_PER_IMPORT) {
                // Do not present a truncated list as exhaustive or choose a
                // first provider as if it were the authoritative engine mount.
                $items = [];
            }
        }
        unset($items);
        return array_filter($matches);
    }
}
