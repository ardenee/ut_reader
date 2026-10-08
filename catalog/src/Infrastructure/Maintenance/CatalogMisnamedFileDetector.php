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
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;

final class CatalogMisnamedFileDetector
{
    public const MAX_IMPORTS_PER_OWNER = 3000;
    public const MAX_OBJECT_PROVIDER_FANOUT = 40;
    private const TERM_CHUNK_SIZE = 350;
    private const MIN_NAME_SIMILARITY_POINTS = 10;
    private const MAX_MATCHED_PATHS_PER_EVIDENCE = 12;

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
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,'
            . 'g.name game_name FROM ue_files f '
            . 'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id AND v.format_version=5 '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE f.id=? AND f.scan_status="verified"',
            [$ownerFileId]
        );
        if ($owner === null) {
            return ['candidates'=>[],'imports_examined'=>0,'truncated'=>false,'ambiguous_terms'=>0];
        }

        $gameId = (int)$owner['game_id'];
        $official = $this->officialBaseGameIdentity($gameId);
        if (isset($official['file_ids'][$ownerFileId])
            || $this->isOfficialPackage((string)$owner['package_name'], $official['names'])) {
            return ['candidates'=>[],'imports_examined'=>0,'truncated'=>false,'ambiguous_terms'=>0];
        }

        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $storageRoot = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($storageRoot === '') {
            throw new \RuntimeException('Catalog storage_path is required for UEDB5 misnamed-file detection.');
        }
        $reader = new Uedb5MetadataReader($storageRoot);
        $ownerSnapshot = $reader->snapshot($gameId, $ownerFileId);
        $importCoverage = [];
        foreach (Uedb5ClassicDependencyResolver::importCoverageRows($ownerSnapshot) as $row) {
            $importCoverage[(int)$row['import_index']] = $row;
        }
        $reader->clearCache($gameId, $ownerFileId);

        $dependencies = (new Uedb5ParityV5ReadService($this->db, is_array($config) ? $config : []))
            ->dependencies($gameId, $ownerFileId);
        $requirements = [];
        $missingExamined = 0;
        $truncated = false;
        foreach ($dependencies as $dependency) {
            if ((string)($dependency['outcome'] ?? '') !== 'missing') {
                continue;
            }
            $missingExamined++;
            if ($missingExamined > self::MAX_IMPORTS_PER_OWNER) {
                $truncated = true;
                break;
            }
            $sourceIndex = (int)($dependency['source_index'] ?? -1);
            $coverage = $importCoverage[$sourceIndex] ?? null;
            if (!is_array($coverage)) {
                continue;
            }
            $packageName = trim((string)($dependency['required_package'] ?? $coverage['root_package'] ?? ''));
            $fullPath = trim((string)($coverage['full_path'] ?? ''));
            $relativePath = trim((string)($coverage['relative_object_path'] ?? ''), '. ');
            if ($packageName === '' || $fullPath === '' || $relativePath === '') {
                continue;
            }
            $parts = preg_split('/[.:]/', $relativePath) ?: [];
            $leaf = trim((string)end($parts));
            if ($leaf === '') {
                continue;
            }
            $packageKey = self::key($packageName);
            $relativeKey = self::key($relativePath);
            $requirementKey = $packageKey . '|' . $relativeKey;
            $requirements[$requirementKey] = [
                'package_name'=>$packageName,
                'package_key'=>$packageKey,
                'full_path'=>$fullPath,
                'relative_path'=>$relativePath,
                'relative_key'=>$relativeKey,
                'leaf'=>$leaf,
                'leaf_key'=>CatalogUnrealIdentityHash::nameKey($leaf),
            ];
        }
        if ($requirements === []) {
            return ['candidates'=>[],'imports_examined'=>$missingExamined,'truncated'=>$truncated,'ambiguous_terms'=>0];
        }

        $requiredPathsByPackage = [];
        $leafRequirements = [];
        foreach ($requirements as $requirementKey => $requirement) {
            $requiredPathsByPackage[$requirement['package_key']][$requirement['relative_key']] = $requirement;
            $leafRequirements[$requirement['leaf_key']][$requirementKey] = $requirement;
        }

        $providerRowsByFile = [];
        $ambiguousTerms = 0;
        foreach (array_chunk(array_keys($leafRequirements), 150) as $leafChunk) {
            $predicates = [];
            $args = [$gameId, $ownerFileId];
            foreach ($leafChunk as $leafKey) {
                $predicates[] = '(o.object_name_hash=? AND o.object_name_length=?)';
                $args[] = md5($leafKey, true);
                $args[] = strlen($leafKey);
            }
            if ($predicates === []) {
                continue;
            }
            $statement = $this->db->prepare(
                'SELECT o.file_id,o.object_index,f.package_name,f.original_name,f.extension,'
                . 'f.name_count,f.import_count,f.export_count,g.name game_name '
                . 'FROM ue_uedb5_object_candidates o '
                . 'JOIN ue_files f ON f.id=o.file_id AND f.scan_status="verified" '
                . 'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id AND v.format_version=5 '
                . 'JOIN ue_games g ON g.id=f.game_id '
                . 'WHERE f.game_id=? AND f.id<>? AND (' . implode(' OR ', $predicates) . ') '
                . 'ORDER BY o.file_id,o.object_index'
            );
            $statement->execute($args);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $providerRowsByFile[(int)$row['file_id']] = $row;
            }
        }
        if ($providerRowsByFile === []) {
            return [
                'candidates'=>[],
                'imports_examined'=>$missingExamined,
                'truncated'=>$truncated,
                'ambiguous_terms'=>0,
            ];
        }

        $matchedProviders = [];
        $leafProviderCounts = [];

        foreach ($providerRowsByFile as $candidateFileId => $providerMeta) {
            if (isset($official['file_ids'][$candidateFileId])
                || $this->isOfficialPackage((string)$providerMeta['package_name'], $official['names'])) {
                continue;
            }
            try {
                $snapshot = $reader->snapshot($gameId, $candidateFileId);
                $exports = Uedb5ClassicDependencyResolver::exportCoverageRows($snapshot);
            } catch (\Throwable) {
                continue;
            } finally {
                $reader->clearCache($gameId, $candidateFileId);
            }

            foreach ($exports as $export) {
                $localPath = trim((string)($export['local_path'] ?? ''), '. ');
                if ($localPath === '') {
                    continue;
                }
                $parts = explode('.', $localPath);
                $leaf = trim((string)end($parts));
                $leafKey = CatalogUnrealIdentityHash::nameKey($leaf);
                if (!isset($leafRequirements[$leafKey])) {
                    continue;
                }
                $relativeKey = self::key($localPath);
                foreach ($leafRequirements[$leafKey] as $requirementKey => $requirement) {
                    if ($relativeKey !== $requirement['relative_key']) {
                        continue;
                    }
                    $matchedProviders[$candidateFileId][$requirementKey] = [
                        'requirement'=>$requirement,
                        'local_path'=>$localPath,
                        'export_index'=>(int)($export['export_index'] ?? -1),
                    ];
                    $leafProviderCounts[$leafKey][$candidateFileId] = true;
                }
            }
        }

        foreach ($leafProviderCounts as $leafKey => $files) {
            if (count($files) > self::MAX_OBJECT_PROVIDER_FANOUT) {
                $ambiguousTerms++;
                foreach (array_keys($files) as $candidateFileId) {
                    foreach ((array)($matchedProviders[$candidateFileId] ?? []) as $requirementKey => $match) {
                        if (($match['requirement']['leaf_key'] ?? '') === $leafKey) {
                            unset($matchedProviders[$candidateFileId][$requirementKey]);
                        }
                    }
                }
            }
        }

        $candidateIds = array_values(array_map('intval', array_keys($matchedProviders)));
        $dependants = $this->resolvedDependantCounts($candidateIds);
        $groups = [];

        foreach ($matchedProviders as $candidateFileId => $matches) {
            $provider = $providerRowsByFile[$candidateFileId] ?? null;
            if (!is_array($provider) || (int)($dependants[$candidateFileId] ?? 0) !== 0) {
                continue;
            }
            foreach ($matches as $match) {
                $requirement = (array)$match['requirement'];
                $suggestedPackage = (string)$requirement['package_name'];
                if ($suggestedPackage === ''
                    || strcasecmp($suggestedPackage, (string)$provider['package_name']) === 0
                    || $this->isOfficialPackage($suggestedPackage, $official['names'])) {
                    continue;
                }
                [$similarityLabel] = self::nameSimilarity((string)$provider['package_name'], $suggestedPackage);
                $collisionSuffixMatch = $similarityLabel === 'copy suffix (1-9)';
                $packageKey = (string)$requirement['package_key'];
                $key = $candidateFileId . ':' . $packageKey;

                if (!isset($groups[$key])) {
                    $requiredSet = (array)($requiredPathsByPackage[$packageKey] ?? []);
                    $groups[$key] = [
                        'candidate_file_id'=>$candidateFileId,
                        'game_id'=>$gameId,
                        'game_name'=>(string)$provider['game_name'],
                        'candidate_original_name'=>(string)$provider['original_name'],
                        'candidate_package_name'=>(string)$provider['package_name'],
                        'candidate_extension'=>(string)$provider['extension'],
                        'candidate_name_count'=>max(0,(int)($provider['name_count'] ?? 0)),
                        'candidate_import_count'=>max(0,(int)($provider['import_count'] ?? 0)),
                        'candidate_export_count'=>max(0,(int)($provider['export_count'] ?? 0)),
                        'suggested_package_name'=>$suggestedPackage,
                        'suggested_filename'=>self::suggestedFilename($suggestedPackage,(string)$provider['extension']),
                        'current_dependants'=>0,
                        'required_objects'=>count($requiredSet),
                        'required_paths'=>array_values(array_map(
                            static fn(array $r): string => (string)$r['full_path'],
                            $requiredSet
                        )),
                        'collision_suffix_match'=>$collisionSuffixMatch,
                        'matched_object_term_ids'=>[],
                        'best_same_file_matches'=>0,
                        'matching_files'=>1,
                        'evidence'=>[[
                            'file_id'=>$ownerFileId,
                            'original_name'=>(string)$owner['original_name'],
                            'package_name'=>(string)$owner['package_name'],
                            'name_count'=>max(0,(int)($owner['name_count'] ?? 0)),
                            'import_count'=>max(0,(int)($owner['import_count'] ?? 0)),
                            'export_count'=>max(0,(int)($owner['export_count'] ?? 0)),
                            'matched_objects'=>0,
                            'matched_paths'=>[],
                        ]],
                    ];
                }
                $groups[$key]['collision_suffix_match'] = !empty($groups[$key]['collision_suffix_match'])
                    || $collisionSuffixMatch;
                $relativeKey = (string)$requirement['relative_key'];
                $groups[$key]['matched_object_term_ids'][$relativeKey] = true;
                if (count((array)$groups[$key]['evidence'][0]['matched_paths']) < self::MAX_MATCHED_PATHS_PER_EVIDENCE) {
                    $groups[$key]['evidence'][0]['matched_paths'][(string)$requirement['full_path']] = true;
                }
            }
        }

        $candidates = [];
        foreach ($groups as $group) {
            $matchedKeys = array_keys((array)$group['matched_object_term_ids']);
            $matched = count($matchedKeys);
            $group['matched_object_term_ids'] = $matchedKeys;
            $group['matching_objects'] = $matched;
            $requiredTotal = max(0,(int)($group['required_objects'] ?? 0));
            $requiredPaths = array_values(array_unique(array_map('strval',(array)$group['required_paths'])));
            sort($requiredPaths,SORT_NATURAL|SORT_FLAG_CASE);
            $group['required_paths'] = $requiredPaths;
            $matchedPathSet = [];
            foreach ((array)$group['evidence'] as $evidenceRow) {
                foreach ((array)($evidenceRow['matched_paths'] ?? []) as $path => $value) {
                    $matchedPathSet[is_string($path) ? $path : (string)$value] = true;
                }
            }
            $group['missing_paths'] = array_values(array_filter(
                $requiredPaths,
                static fn(string $path): bool => !isset($matchedPathSet[$path])
            ));
            $group['coverage_status'] = $requiredTotal > 0 && $matched >= $requiredTotal ? 'full' : 'partial';
            $group['coverage_percent'] = $requiredTotal > 0
                ? min(100,(int)floor(($matched * 100) / $requiredTotal))
                : 0;
            $group['best_same_file_matches'] = $matched;
            $group['evidence'][0]['matched_objects'] = $matched;
            $paths = array_keys((array)($group['evidence'][0]['matched_paths'] ?? []));
            sort($paths,SORT_NATURAL|SORT_FLAG_CASE);
            $group['evidence'][0]['matched_paths'] = array_slice($paths,0,self::MAX_MATCHED_PATHS_PER_EVIDENCE);
            $candidates[] = self::rankCandidate($group);
        }

        usort($candidates,[self::class,'compareCandidates']);
        return [
            'candidates'=>$candidates,
            'imports_examined'=>$missingExamined,
            'truncated'=>$truncated,
            'ambiguous_terms'=>max(0,$ambiguousTerms),
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
                . 'FROM ue_uedb5_dependency_edges '
                . 'WHERE outcome=1 AND resolved_file_id IN (' . $placeholders . ') AND file_id<>resolved_file_id '
                . 'GROUP BY resolved_file_id'
            );
            $statement->execute($chunk);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $counts[(int)$row['resolved_file_id']] = (int)$row['dependant_count'];
            }
        }
        return $counts;
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));
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
