<?php
/**
 * Detects likely historical filename/package-name corruption from dependency evidence.
 *
 * The detector never renames files. It compares unresolved import object terms with
 * exact export object terms in the same game, rejects highly ambiguous object names,
 * and ranks candidate providers using repeated matches plus current dependant count.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;

final class CatalogMisnamedFileDetector
{
    public const MAX_IMPORTS_PER_OWNER = 3000;
    public const MAX_OBJECT_PROVIDER_FANOUT = 40;
    private const TERM_CHUNK_SIZE = 350;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Scan one file that owns unresolved imports.
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

        $statement = $this->db->prepare(
            'SELECT import_index,required_package_term_id,import_object_term_id '
            . 'FROM ue_dependency_links '
            . 'WHERE file_id=? AND resolved_file_id IS NULL '
            . 'AND required_package_term_id IS NOT NULL AND import_object_term_id IS NOT NULL '
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

        /** @var array<int,array<int,true>> $requirementsByObject */
        $requirementsByObject = [];
        $requiredPackageTermIds = [];
        $objectTermIds = [];
        foreach ($dependencies as $dependency) {
            $objectTermId = (int)($dependency['import_object_term_id'] ?? 0);
            $packageTermId = (int)($dependency['required_package_term_id'] ?? 0);
            if ($objectTermId < 1 || $packageTermId < 1) {
                continue;
            }
            $requirementsByObject[$objectTermId][$packageTermId] = true;
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
            (int)$owner['game_id'],
            $ownerFileId
        );
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
            foreach (array_keys($requirementsByObject[$objectTermId] ?? []) as $packageTermId) {
                $packageTermId = (int)$packageTermId;
                $suggestedPackage = trim((string)($packageNames[$packageTermId] ?? ''));
                if ($suggestedPackage === '' || strcasecmp($suggestedPackage, (string)$provider['package_name']) === 0) {
                    continue;
                }
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
                        'current_dependants' => (int)($dependants[$candidateFileId] ?? 0),
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
                $groups[$key]['matched_object_term_ids'][(string)$objectTermId] = true;
            }
        }

        $candidates = [];
        foreach ($groups as $group) {
            $termIds = array_map('intval', array_keys((array)$group['matched_object_term_ids']));
            $matched = count($termIds);
            if ($matched < 2) {
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

        $score = min(65, $best * 15)
            + min(20, $matchingFiles * 5)
            + ($dependants === 0 ? 35 : ($dependants === 1 ? 8 : 0))
            + $similarityPoints;

        if ($best >= 3 && $dependants === 0 && $similarityPoints >= 20) {
            $confidence = 'very_high';
        } elseif (($best >= 2 && $dependants === 0 && $similarityPoints >= 10)
            || ($best >= 4 && $dependants === 0)
            || ($best >= 3 && $similarityPoints >= 20)) {
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
                'SELECT e.object_term_id,c.id file_id,c.game_id,c.package_name,c.original_name,c.extension,g.name game_name '
                . 'FROM ue_export_lookup e '
                . 'JOIN ue_files c ON c.id=e.file_id AND c.scan_status="verified" '
                . 'JOIN ue_games g ON g.id=c.game_id '
                . 'WHERE e.object_term_id IN (' . $placeholders . ') AND c.game_id=? AND c.id<>? '
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

    /** @return array{0:string,1:int,2:int|null} */
    private static function nameSimilarity(string $currentPackage, string $suggestedPackage): array
    {
        $currentLeaf = self::packageLeaf($currentPackage);
        $suggestedLeaf = self::packageLeaf($suggestedPackage);
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
