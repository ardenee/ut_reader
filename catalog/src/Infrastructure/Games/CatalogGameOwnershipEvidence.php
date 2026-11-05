<?php
/**
 * Advisory catalogue game provenance. A UE package header does not contain an
 * authoritative game ID. Original acquisition directories and exact indexed
 * bytes are evidence; export compatibility alone is NOT ownership.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;

final class CatalogGameOwnershipEvidence
{
    private const MAX_MATCHES_PER_GAME = 3;
    private const MD5_BATCH = 160;

    public function __construct(private readonly PDO $db) {}

    /**
     * One bounded set of indexed lookups per game. No package reads and no
     * dependency rebuilds on the web request.
     *
     * @param list<array<string,mixed>> $files
     * @param list<array<string,mixed>> $games
     * @return array<int,array<string,mixed>>
     */
    public function assess(array $files, array $games): array
    {
        $gameIds = [];
        $slugIds = [];
        $gameNames = [];
        foreach ($games as $game) {
            $id = (int)($game['id'] ?? 0);
            if ($id > 0) {
                $gameIds[$id] = true;
                $gameNames[$id] = (string)($game['name'] ?? ('Game #' . $id));
                $slug = strtolower(trim((string)($game['slug'] ?? '')));
                if ($slug !== '') $slugIds[$slug] = $id;
            }
        }
        $lookup = [];
        $sourceFiles = [];
        foreach ($files as $file) {
            $id = (int)($file['id'] ?? 0);
            if ($id < 1) continue;
            $sourceFiles[$id] = $file;
            $fingerprint = self::fingerprint($file);
            if ($fingerprint !== '') {
                $lookup[$fingerprint]['md5'] = strtolower(trim((string)$file['md5']));
                $lookup[$fingerprint]['sources'][$id] = true;
            }
        }
        $matches = [];
        if ($lookup !== []) {
            $md5s = array_values(array_unique(array_column($lookup, 'md5')));
            // The game_id + md5 unique index avoids any full-catalogue scan.
            foreach (array_keys($gameIds) as $gameId) {
                foreach (array_chunk($md5s, self::MD5_BATCH) as $chunk) {
                    $sql = 'SELECT id,game_id,md5,sha1,file_size FROM ue_files '
                        . 'WHERE game_id=? AND scan_status="verified" AND md5 IN ('
                        . implode(',', array_fill(0, count($chunk), '?')) . ')';
                    $statement = $this->db->prepare($sql);
                    $statement->execute(array_merge([$gameId], $chunk));
                    while ($match = $statement->fetch(PDO::FETCH_ASSOC)) {
                        $key = self::fingerprint($match);
                        if ($key === '' || !isset($lookup[$key]['sources'])) continue;
                        foreach ($lookup[$key]['sources'] as $sourceId => $_) {
                            if ((int)$match['id'] === $sourceId) continue;
                            $matches[$sourceId][$gameId][] = (int)$match['id'];
                        }
                    }
                }
            }
        }
        $result = [];
        foreach ($sourceFiles as $id => $file) {
            $pathIds = self::directoryGameIds((string)($file['source_relative_path'] ?? ''), $slugIds);
            $knownMatches = [];
            foreach ($matches[$id] ?? [] as $gameId => $ids) {
                $knownMatches[(int)$gameId] = [
                    'game_id' => (int)$gameId,
                    'game_name' => $gameNames[(int)$gameId] ?? ('Game #' . $gameId),
                    'count' => count($ids),
                    'sample_file_ids' => array_slice($ids, 0, self::MAX_MATCHES_PER_GAME),
                ];
            }
            ksort($knownMatches, SORT_NUMERIC);
            $pathId = count($pathIds) === 1 ? $pathIds[0] : 0;
            $matchIds = array_keys($knownMatches);
            // A reused original package can be indexed in another game but
            // not separately in its own original game (other than this row).
            // That is resource reuse, NOT contradictory game provenance.
            if (count($pathIds) > 1) {
                $kind = 'conflict';
                $label = 'Conflicting game evidence';
            } elseif ($pathId > 0 && isset($knownMatches[$pathId])) {
                $kind = 'corroborated';
                $label = 'Directory + identical file';
            } elseif ($pathId > 0) {
                $kind = 'directory';
                $label = 'Original directory clue';
            } elseif (count($matchIds) > 1) {
                $kind = 'reused';
                $label = 'Identical in multiple games';
            } elseif (count($matchIds) === 1) {
                $kind = 'identical';
                $label = 'Identical indexed file';
            } else {
                $kind = 'unknown';
                $label = 'Game not established';
            }
            // Matching a byte-identical file in another game cannot itself
            // establish the original game (Unreal II assets also ship in UT2004).
            // Only the acquisition directory may suggest an original owner.
            $suggestedId = in_array($kind, ['corroborated', 'directory'], true)
                ? $pathId : 0;
            $result[$id] = [
                'kind' => $kind,
                'label' => $label,
                'suggested_game_id' => $suggestedId,
                'suggested_game_name' => $gameNames[$suggestedId] ?? '',
                'directory_game_ids' => $pathIds,
                'directory_game_names' => array_values(array_map(
                    static fn(int $gameId): string => $gameNames[$gameId] ?? ('Game #' . $gameId),
                    $pathIds
                )),
                'exact_games' => array_values($knownMatches),
                'exact_game_ids' => $matchIds,
                'header_hint' => self::versionFamilyHint($file),
                'path' => (string)($file['source_relative_path'] ?? ''),
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $file */
    public static function fingerprint(array $file): string
    {
        $md5 = strtolower(trim((string)($file['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($file['sha1'] ?? '')));
        $size = (int)($file['file_size'] ?? 0);
        return $size > 0 && preg_match('/^[a-f0-9]{32}$/D', $md5)
            && preg_match('/^[a-f0-9]{40}$/D', $sha1)
            ? $size . ':' . $md5 . ':' . $sha1 : '';
    }

    /**
     * Directory segments only; never infer a game from a package basename,
     * mod title, generic 'Unreal' folder or version.
     *
     * @param array<string,int> $slugIds
     * @return list<int>
     */
    public static function directoryGameIds(string $path, array $slugIds): array
    {
        $parts = explode('/', str_replace('\\', '/', trim($path)));
        array_pop($parts); // Always exclude filename.
        $known = [
            'unreal tournament 2004' => 'ut2004', 'ut2004' => 'ut2004',
            'unreal tournament 2003' => 'ut2003', 'ut2003' => 'ut2003',
            'unreal ii the awakening' => 'unreal2', 'unreal2xmp' => 'unreal2',
            'unreal ii' => 'unreal2', 'unreal 2' => 'unreal2',
            'unreal tournament 3' => 'ut3', 'ut3' => 'ut3',
            'unreal tournament 4' => 'ut4', 'ut4' => 'ut4',
            'unreal tournament' => 'ut99', 'ut99' => 'ut99',
            'unreal gold' => 'unrealgold',
            'satisfactory' => 'satisfactory',
        ];
        $ids = [];
        foreach ($parts as $part) {
            $key = strtolower(trim($part));
            $slug = $known[$key] ?? '';
            $id = $slug !== '' ? (int)($slugIds[$slug] ?? 0) : 0;
            if ($id > 0) $ids[$id] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /** @param array<string,mixed> $file */
    public static function versionFamilyHint(array $file): string
    {
        $engine = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        $ver = (int)($file['detected_package_version'] ?? 0);
        $lic = (int)($file['detected_licensee_version'] ?? 0);
        if ($engine === 'UE2' && $lic === 29 && $ver >= 119) {
            return 'UE2 UT2003/UT2004-era package family; not a unique game identifier';
        }
        if ($engine === 'UE2' && $ver === 110 && in_array($lic, [2481, 2609], true)) {
            return 'Unreal II-era package family; mod reuse is possible';
        }
        return '';
    }
}
