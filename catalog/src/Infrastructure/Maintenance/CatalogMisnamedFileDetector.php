<?php
/**
 * Detects likely historical filename/package-name corruption from dependency evidence.
 *
 * The detector never renames files. It considers true missing dependency rows only,
 * requires exact relative-object-path matches against exports in the same game,
 * excludes official/base-game/common-package noise, and only retains community
 * providers whose current package name is close to the missing package name and
 * which currently have no resolved dependants. Browser/download copy suffixes
 * such as "Package(2)" and "Package (2)" are treated as a dedicated high-signal
 * rename pattern when the stripped package identity is actually missing.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;

final class CatalogMisnamedFileDetector
{
    public const MAX_IMPORTS_PER_OWNER = 3000;
    public const MAX_OBJECT_PROVIDER_FANOUT = 40;
    private const TERM_CHUNK_SIZE = 350;
    private const MIN_NAME_SIMILARITY_POINTS = 10;

    /** @var array<int,array{names:array<string,true>,file_ids:array<int,true>}> */
    private array $officialIdentityCache = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Scan one community file that owns true missing imports.
     *
     * @return array{candidates:list<array<string,mixed>>,imports_examined:int,truncated:bool,ambiguous_terms:int}
     */
    public function scanOwner(int $ownerFileId): array
    {
        $owner = $this->one(
            'SELECT f.id,f.game_id,f.package_name,f.original_name,g.name game_name '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE f.id=? AND f.scan_status="verified"',
            [$ownerFileId]
        );
        if ($owner === null) {
            return ['candidates' => [], 'imports_examined' => 0, 'truncated' => false, 'ambiguous_terms' => 0];
        }

        $gameId = (int)$owner['game_id'];
        $official = $this->officialBaseGameIdentity($gameId);
        if (isset($official['file_ids'][(int)$owner['id']])
            || $this->isOfficialPackage((string)$owner['package_name'], $official['names'])) {
            return ['candidates' => [], 'imports_examined' => 0, 'truncated' => false, 'ambiguous_terms' => 0];
        }

        // Status 0 is the authoritative compact "missing" state. Status 3
        // (common) is deliberately excluded. required_path_hash represents the
        // object path below the package root, so package-name damage can be
        // detected without reducing the comparison to a coincidental leaf name.
        $statement = $this->db->prepare(
            'SELECT import_index,required_package_term_id,import_object_term_id,'
            . 'HEX(required_path_hash) required_path_hash_hex '
            . 'FROM ue_dependency_links '
            . 'WHERE file_id=? AND status=0 AND resolved_file_id IS NULL '
            . 'AND required_package_term_id IS NOT NULL AND import_object_term_id IS NOT NULL '
            . 'AND required_path_hash IS NOT NULL '
            . 'ORDER BY import_index LIMIT ' . (self::MAX_IMPORTS_PER_OWNER + 1)
        );
        $statement->execute([$ownerFileId]);
        $dependencies = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $truncated = count($dependencies) > self::MAX_IMPORTS_PER_OWNER;
        if ($truncated) {
            $dependencies = array_slice($dependencies, 0, self::MAX_IMPORTS_PER_OWNER);
        }
        if ($dependencies === []) {
            return ['candidates' => [], 'imports_examined' => 0, 'truncated' => $truncated, 'ambiguous_terms' => 0];
        }

        /** @var array<int,array<int,array<string,true>>> $requirementsByObject */
        $requirementsByObject = [];
        $requiredPackageTermIds = [];
        $objectTermIds = [];
        foreach ($dependencies as $dependency) {
            $objectTermId = (int)($dependency['import_object_term_id'] ?? 0);
            $packageTermId = (int)($dependency['required_package_term_id'] ?? 0);
            $pathHash = strtoupper(trim((string)($dependency['required_path_hash_hex'] ?? '')));
            if ($objectTermId < 1 || $packageTermId < 1 || $pathHash === '') {
                continue;
            }
            $requirementsByObject[$objectTermId][$packageTermId][$pathHash] = true;
            $requiredPackageTermIds[$packageTermId] = true;
            $objectTermIds[$objectTermId] = true;
        }
        if ($objectTermIds === []) {
            return [
                'candidates' => [],
                'imports_examined' => count($dependencies),
                'truncated' => $truncated,
                'ambiguous_terms' => 0,
            ];
        }

        $safeObjectTermIds = $this->safeObjectTerms(array_map('intval', array_keys($objectTermIds)));
        $ambiguousTerms = count($objectTermIds) - count($safeObjectTermIds);
        if ($safeObjectTermIds === []) {
            return [
                'candidates' => [],
                'imports_examined' => count($dependencies),
                'truncated' => $truncated,
                'ambiguous_terms' => max(0, $ambiguousTerms),
            ];
        }

        $packageNames = $this->termValues(array_map('intval', array_keys($requiredPackageTermIds)));
        $providers = $this->providersForTerms(
            $safeObjectTermIds,
            $gameId,
            $ownerFileId
        );
        $providers = array_values(array_filter(
            $providers,
            fn(array $provider): bool => !isset($official['file_ids'][(int)$provider['file_id']])
                && !$this->isOfficialPackage((string)$provider['package_name'], $official['names'])
        ));
        if ($providers === []) {
            return [
                'candidates' => [],
                'imports_examined' => count($dependencies),
                'truncated' => $truncated,
                'ambiguous_terms' => max(0, $ambiguousTerms),
            ];
        }

        $candidateIds = [];
        foreach ($providers as $provider) {
            $candidateIds[(int)$provider['file_id']] = true;
        }
        $dependants = $this->resolvedDependantCounts(array_map('intval', array_keys($candidateIds)));

        /** @var array<string,array<string,mixed>> $groups */
        $groups = [];
        foreach ($providers as $provider) {
            $objectTermId = (int)$provider['object_term_id'];
            $candidateFileId = (int)$provider['file_id'];
            $providerPathHash = strtoupper(trim((string)($provider['path_hash_hex'] ?? '')));

            // A historical filename-cleanup victim should be effectively orphaned.
            // If the current package identity is already resolving dependencies,
            // do not suggest replacing it with another package name.
            if ($providerPathHash === '' || (int)($dependants[$candidateFileId] ?? 0) !== 0) {
                continue;
            }

            foreach (($requirementsByObject[$objectTermId] ?? []) as $packageTermId => $requiredPathHashes) {
                $packageTermId = (int)$packageTermId;

                // Leaf-name collisions are not evidence. The object hierarchy below
                // the package root must be exactly the same on both sides.
                if (!isset($requiredPathHashes[$providerPathHash])) {
                    continue;
                }

                $suggestedPackage = trim((string)($packageNames[$packageTermId] ?? ''));
                if ($suggestedPackage === ''
                    || strcasecmp($suggestedPackage, (string)$provider['package_name']) === 0
                    || $this->isOfficialPackage($suggestedPackage, $official['names'])) {
                    continue;
                }

                [$similarityLabel, $similarityPoints] = self::nameSimilarity(
                    (string)$provider['package_name'],
                    $suggestedPackage
                );
                if ($similarityPoints < self::MIN_NAME_SIMILARITY_POINTS) {
                    continue;
                }
                $collisionSuffixMatch = $similarityLabel === 'copy suffix (1-9)';

                $key = $candidateFileId . ':' . $packageTermId;
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'candidate_file_id' => $candidateFileId,
                        'game_id' => (int)$provider['game_id'],
                        'game_name' => (string)$provider['game_name'],
                        'candidate_original_name' => (string)$provider['original_name'],
                        'candidate_package_name' => (string)$provider['package_name'],
                        'candidate_extension' => (string)$provider['extension'],
                        'suggested_package_name' => $suggestedPackage,
                        'suggested_filename' => self::suggestedFilename(
                            $suggestedPackage,
                            (string)$provider['extension']
                        ),
                        'current_dependants' => 0,
                        'collision_suffix_match' => $collisionSuffixMatch,
                        'matched_object_term_ids' => [],
                        'best_same_file_matches' => 0,
                        'matching_files' => 1,
                        'evidence' => [[
                            'file_id' => $ownerFileId,
                            'original_name' => (string)$owner['original_name'],
                            'package_name' => (string)$owner['package_name'],
                            'matched_objects' => 0,
                        ]],
                    ];
                }
                if ($collisionSuffixMatch) {
                    $groups[$key]['collision_suffix_match'] = true;
                }
                $groups[$key]['matched_object_term_ids'][(string)$objectTermId] = true;
            }
        }

        $candidates = [];
        foreach ($groups as $group) {
            $termIds = array_map('intval', array_keys((array)$group['matched_object_term_ids']));
            $matched = count($termIds);
            if ($matched < 2 && empty($group['collision_suffix_match'])) {
                continue;
            }
            $group['matched_object_term_ids'] = $termIds;
            $group['matching_objects'] = $matched;
            $group['best_same_file_matches'] = $matched;
            $group['evidence'][0]['matched_objects'] = $matched;
            $candidates[] = self::rankCandidate($group);
        }

        usort($candidates, [self::class, 'compareCandidates']);
        return [
            'candidates' => $candidates,
            'imports_examined' => count($dependencies),
            'truncated' => $truncated,
            'ambiguous_terms' => max(0, $ambiguousTerms),
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public static function rankCandidate(array $candidate): array
    {
        $best = max(0, (int)($candidate['best_same_file_matches'] ?? $candidate['matching_objects'] ?? 0));
        $matchingFiles = max(1, (int)($candidate['matching_files'] ?? 1));
        $dependants = max(0, (int)($candidate['current_dependants'] ?? 0));
        [$similarity, $similarityPoints, $distance] = self::nameSimilarity(
            (string)($candidate['candidate_package_name'] ?? ''),
            (string)($candidate['suggested_package_name'] ?? '')
        );

        $collisionSuffix = !empty($candidate['collision_suffix_match']);
        $score = min(65, $best * 15)
            + min(20, $matchingFiles * 5)
            + ($dependants === 0 ? 35 : ($dependants === 1 ? 8 : 0))
            + $similarityPoints
            + ($collisionSuffix ? 15 : 0);

        if ($best >= 3 && $dependants === 0 && $similarityPoints >= 20) {
            $confidence = 'very_high';
        } elseif ($collisionSuffix && $best >= 1 && $dependants === 0) {
            $confidence = 'high';
        } elseif ($best >= 2 && $dependants === 0 && $similarityPoints >= self::MIN_NAME_SIMILARITY_POINTS) {
            $confidence = 'high';
        } else {
            $confidence = 'possible';
        }

        $candidate['name_similarity'] = $similarity;
        $candidate['name_distance'] = $distance;
        $candidate['score'] = $score;
        $candidate['confidence'] = $confidence;
        $candidate['best_same_file_matches'] = $best;
        $candidate['matching_files'] = $matchingFiles;
        $candidate['current_dependants'] = $dependants;
        return $candidate;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    public static function compareCandidates(array $left, array $right): int
    {
        $confidenceOrder = ['very_high' => 3, 'high' => 2, 'possible' => 1];
        $leftConfidence = $confidenceOrder[(string)($left['confidence'] ?? '')] ?? 0;
        $rightConfidence = $confidenceOrder[(string)($right['confidence'] ?? '')] ?? 0;
        return [$rightConfidence, (int)($right['score'] ?? 0), (int)($right['best_same_file_matches'] ?? 0)]
            <=> [$leftConfidence, (int)($left['score'] ?? 0), (int)($left['best_same_file_matches'] ?? 0)];
    }

    /** @param list<int> $termIds @return list<int> */
    private function safeObjectTerms(array $termIds): array
    {
        $safe = [];
        foreach (array_chunk(array_values(array_unique($termIds)), self::TERM_CHUNK_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT object_term_id,COUNT(DISTINCT file_id) provider_count '
                . 'FROM ue_export_lookup WHERE object_term_id IN (' . $placeholders . ') '
                . 'GROUP BY object_term_id HAVING COUNT(DISTINCT file_id)<=' . self::MAX_OBJECT_PROVIDER_FANOUT
            );
            $statement->execute($chunk);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $safe[] = (int)$row['object_term_id'];
            }
        }
        return array_values(array_unique(array_filter($safe, static fn(int $id): bool => $id > 0)));
    }

    /** @param list<int> $termIds @return list<array<string,mixed>> */
    private function providersForTerms(array $termIds, int $gameId, int $ownerFileId): array
    {
        $providers = [];
        foreach (array_chunk($termIds, self::TERM_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT e.object_term_id,HEX(e.path_hash) path_hash_hex,'
                . 'c.id file_id,c.game_id,c.package_name,c.original_name,c.extension,g.name game_name '
                . 'FROM ue_export_lookup e '
                . 'JOIN ue_files c ON c.id=e.file_id AND c.scan_status="verified" '
                . 'JOIN ue_games g ON g.id=c.game_id '
                . 'WHERE e.object_term_id IN (' . $placeholders . ') AND c.game_id=? AND c.id<>? '
                . 'AND e.path_hash IS NOT NULL '
                . 'ORDER BY e.object_term_id,c.id'
            );
            $statement->execute(array_merge($chunk, [$gameId, $ownerFileId]));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $providers[] = $row;
            }
        }
        return $providers;
    }

    /** @param list<int> $termIds @return array<int,string> */
    private function termValues(array $termIds): array
    {
        $values = [];
        foreach (array_chunk(array_values(array_unique($termIds)), self::TERM_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id,CONVERT(value_prefix USING utf8mb4) value_text '
                . 'FROM ue_terms WHERE id IN (' . $placeholders . ')'
            );
            $statement->execute($chunk);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $values[(int)$row['id']] = (string)$row['value_text'];
            }
        }
        return $values;
    }

    /** @param list<int> $fileIds @return array<int,int> */
    private function resolvedDependantCounts(array $fileIds): array
    {
        $counts = [];
        foreach (array_chunk(array_values(array_unique($fileIds)), self::TERM_CHUNK_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT resolved_file_id,COUNT(DISTINCT file_id) dependant_count '
                . 'FROM ue_dependency_links '
                . 'WHERE resolved_file_id IN (' . $placeholders . ') AND file_id<>resolved_file_id '
                . 'GROUP BY resolved_file_id'
            );
            $statement->execute($chunk);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $counts[(int)$row['resolved_file_id']] = (int)$row['dependant_count'];
            }
        }
        return $counts;
    }

    /** @return array{names:array<string,true>,file_ids:array<int,true>} */
    private function officialBaseGameIdentity(int $gameId): array
    {
        if (isset($this->officialIdentityCache[$gameId])) {
            return $this->officialIdentityCache[$gameId];
        }

        $identity = ['names' => [], 'file_ids' => []];
        if ($gameId < 1) {
            return $this->officialIdentityCache[$gameId] = $identity;
        }

        $statement = $this->db->prepare(
            'SELECT b.source_file_id,b.package_name,b.original_name,'
            . 'f.package_name source_package_name,f.original_name source_original_name '
            . 'FROM ue_base_game_files b '
            . 'LEFT JOIN ue_files f ON f.id=b.source_file_id AND f.game_id=b.game_id '
            . 'WHERE b.game_id=?'
        );
        $statement->execute([$gameId]);
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sourceFileId = (int)($row['source_file_id'] ?? 0);
            if ($sourceFileId > 0) {
                $identity['file_ids'][$sourceFileId] = true;
            }
            foreach ([(string)($row['package_name'] ?? ''), (string)($row['source_package_name'] ?? '')] as $packageName) {
                foreach (self::packageIdentityKeys($packageName) as $key) {
                    $identity['names'][$key] = true;
                }
            }
            foreach ([(string)($row['original_name'] ?? ''), (string)($row['source_original_name'] ?? '')] as $filename) {
                foreach (self::packageIdentityKeys(self::filenameStem($filename)) as $key) {
                    $identity['names'][$key] = true;
                }
            }
        }

        return $this->officialIdentityCache[$gameId] = $identity;
    }

    /** @param array<string,true> $officialNames */
    private function isOfficialPackage(string $packageName, array $officialNames): bool
    {
        foreach (self::packageIdentityKeys($packageName) as $key) {
            if (isset($officialNames[$key])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function packageIdentityKeys(string $packageName): array
    {
        $packageName = trim(str_replace('\\', '/', $packageName));
        if ($packageName === '') {
            return [];
        }
        $keys = [];
        foreach ([$packageName, self::packageLeaf($packageName)] as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
            $keys[$key] = true;
        }
        return array_keys($keys);
    }

    private static function filenameStem(string $filename): string
    {
        $filename = trim(str_replace('\\', '/', $filename));
        if ($filename === '') {
            return '';
        }
        $slash = strrpos($filename, '/');
        $leaf = $slash === false ? $filename : substr($filename, $slash + 1);
        $dot = strrpos($leaf, '.');
        return $dot === false ? $leaf : substr($leaf, 0, $dot);
    }

    /** @return array{0:string,1:int,2:int|null} */
    private static function nameSimilarity(string $currentPackage, string $suggestedPackage): array
    {
        $currentLeaf = self::packageLeaf($currentPackage);
        $suggestedLeaf = self::packageLeaf($suggestedPackage);

        // Common browser/download collision names append "(N)" to a duplicate
        // filename. Treat only a single digit 1-9 at the very end of the package
        // stem as this pattern; parentheses elsewhere remain ordinary package
        // identity. Both "Name(2)" and "Name (2)" normalize to "Name".
        $collisionBase = self::collisionSuffixBase($currentLeaf);
        if ($collisionBase !== ''
            && strcasecmp($collisionBase, trim($suggestedLeaf)) === 0) {
            return ['copy suffix (1-9)', 40, 0];
        }

        $currentNormalized = self::normalizedName($currentLeaf);
        $suggestedNormalized = self::normalizedName($suggestedLeaf);
        if ($currentNormalized !== '' && hash_equals($currentNormalized, $suggestedNormalized)) {
            return ['same letters/numbers after punctuation cleanup', 30, 0];
        }

        $currentLower = strtolower($currentLeaf);
        $suggestedLower = strtolower($suggestedLeaf);
        if ($currentLower === '' || $suggestedLower === ''
            || preg_match('/^[\x20-\x7E]+$/', $currentLower) !== 1
            || preg_match('/^[\x20-\x7E]+$/', $suggestedLower) !== 1) {
            return ['different', 0, null];
        }
        $distance = levenshtein($currentLower, $suggestedLower);
        if ($distance <= 2) {
            return ['very similar', 20, $distance];
        }
        if ($distance <= 4) {
            return ['similar', 10, $distance];
        }
        return ['different', 0, $distance];
    }

    private static function collisionSuffixBase(string $packageLeaf): string
    {
        $packageLeaf = trim($packageLeaf);
        if ($packageLeaf === ''
            || preg_match('/^(.*?)[\\t ]*\\(([1-9])\\)$/u', $packageLeaf, $match) !== 1) {
            return '';
        }
        return trim((string)($match[1] ?? ''));
    }

    private static function packageLeaf(string $packageName): string
    {
        $packageName = trim(str_replace('\\', '/', $packageName), '/');
        $slash = strrpos($packageName, '/');
        return $slash === false ? $packageName : substr($packageName, $slash + 1);
    }

    private static function normalizedName(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    private static function suggestedFilename(string $packageName, string $extension): string
    {
        $leaf = self::packageLeaf($packageName);
        $extension = strtolower(trim($extension));
        return $leaf . ($extension !== '' ? '.' . $extension : '');
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
