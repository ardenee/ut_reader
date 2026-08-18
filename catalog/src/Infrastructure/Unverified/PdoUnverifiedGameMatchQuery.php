<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Ranks configured games for database-staged unverified files.
 * Why: Dependency evidence comes from current compact metadata while candidate exports come from compressed unverified staging.
 * Role: Infrastructure read model for single and bulk Unverified Files matching.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class PdoUnverifiedGameMatchQuery
{
    private const TERM_ID_BATCH_SIZE = 350;
    private const DEPENDENCY_TERM_BATCH_SIZE = 250;

    private readonly CatalogUnverifiedStagingIndex $staging;
    private readonly CatalogUnverifiedMetadataStore $metadata;

    /** @var array<string,list<int>>|null */
    private ?array $requiredPackageTermIdsByKey = null;

    public function __construct(private readonly PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db);
        $this->metadata = new CatalogUnverifiedMetadataStore($db);
    }

    /** @return list<array<string,mixed>> */
    public function one(int $fileId): array
    {
        $matches = $this->bulk([$fileId]);
        return $matches[$fileId] ?? [];
    }

    /**
     * @param list<int> $fileIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function bulk(array $fileIds): array
    {
        $this->staging->ensureSchema();

        $fileIds = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($fileIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $files = \catalog_all(
            $this->db,
            'SELECT id,package_name,extension,detected_engine_key,detected_package_version,detected_licensee_version '
            . 'FROM ue_files WHERE scan_status="unverified" AND id IN (' . $placeholders . ')',
            $fileIds
        );
        if ($files === []) {
            return [];
        }

        $filesById = [];
        $packageNames = [];
        foreach ($files as $file) {
            $id = (int)$file['id'];
            $filesById[$id] = $file;
            $package = trim((string)$file['package_name']);
            if ($package !== '') {
                $packageNames[$this->key($package)] = $package;
            }
        }

        $dependencyEvidence = $this->dependencyEvidenceForPackages($packageNames);
        $evidenceByFile = [];
        foreach ($filesById as $fileId => $file) {
            $packageKey = $this->key((string)$file['package_name']);
            $rows = $dependencyEvidence[$packageKey] ?? [];
            if ($rows === []) {
                continue;
            }

            // bulk() already loaded the authoritative current package name for
            // every staged file. Pass it through so metadata loading does not
            // issue a second ue_files lookup for each package.
            $snapshot = $this->metadata->load($fileId, (string)$file['package_name']);
            $exportPaths = [];
            foreach ((array)($snapshot['exports'] ?? []) as $export) {
                $path = trim((string)($export['full_path'] ?? ''));
                if ($path !== '') {
                    $exportPaths[$this->key($path)] = true;
                }
            }

            $byGame = [];
            foreach ($rows as $row) {
                $gameId = (int)$row['game_id'];
                $dependencyId = (string)$row['dependency_key'];
                $ownerId = (int)$row['owner_file_id'];
                $byGame[$gameId]['game_name'] = (string)$row['game_name'];
                $byGame[$gameId]['dependencies'][$dependencyId] = true;
                $byGame[$gameId]['owners'][$ownerId] = true;
                if (isset($exportPaths[$this->key((string)$row['required_object_path'])])) {
                    $byGame[$gameId]['exact'][$dependencyId] = true;
                }
            }
            foreach ($byGame as $gameId => $evidence) {
                $evidenceByFile[$fileId][$gameId] = [
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'game_name' => (string)($evidence['game_name'] ?? ''),
                    'import_count' => count((array)($evidence['dependencies'] ?? [])),
                    'owner_count' => count((array)($evidence['owners'] ?? [])),
                    'exact_object_matches' => count((array)($evidence['exact'] ?? [])),
                ];
            }
        }

        $games = \catalog_all(
            $this->db,
            'SELECT g.id game_id,g.name game_name,'
            . 'p.id profile_id,p.profile_name,p.engine_key,p.allowed_extensions_json,p.compatibility_rules_json,'
            . 'p.package_version_min,p.package_version_max,p.licensee_version_min,p.licensee_version_max,p.confidence_policy '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name'
        );

        $result = [];
        foreach ($filesById as $fileId => $file) {
            $detectedEngine = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
            $extension = \catalog_clean_unreal_extension((string)$file['extension']);
            $packageVersion = $file['detected_package_version'] !== null
                ? (int)$file['detected_package_version'] : null;
            $licenseeVersion = $file['detected_licensee_version'] !== null
                ? (int)$file['detected_licensee_version'] : null;
            $signedUe4Version = $packageVersion !== null && $packageVersion < 0;
            $rows = [];

            foreach ($games as $game) {
                $gameId = (int)$game['game_id'];
                $evidence = $evidenceByFile[$fileId][$gameId] ?? [];
                $imports = (int)($evidence['import_count'] ?? 0);
                $owners = (int)($evidence['owner_count'] ?? 0);
                $exact = (int)($evidence['exact_object_matches'] ?? 0);
                $unmatched = max(0, $imports - $exact);
                $matchPercent = $imports > 0 ? round(($exact / $imports) * 100, 1) : null;

                $profileExists = (int)($game['profile_id'] ?? 0) > 0;
                $profileEngine = strtoupper(trim((string)($game['engine_key'] ?? '')));
                $allowedExtensions = $profileExists ? \gp_extensions($game) : [];
                $extensionOk = $profileExists
                    && ($allowedExtensions === [] || in_array($extension, $allowedExtensions, true));
                $compatibility = $profileExists
                    ? \gp_compatibility_for_file(
                        $game,
                        $extension,
                        $packageVersion,
                        $licenseeVersion,
                        $detectedEngine
                    )
                    : null;
                $engineOk = $profileExists
                    && ($profileEngine === $detectedEngine || $compatibility !== null);

                $versionOk = $profileExists;
                if ($versionOk && !$signedUe4Version && $packageVersion !== null && $compatibility === null) {
                    if ($game['package_version_min'] !== null && $packageVersion < (int)$game['package_version_min']) {
                        $versionOk = false;
                    }
                    if ($game['package_version_max'] !== null && $packageVersion > (int)$game['package_version_max']) {
                        $versionOk = false;
                    }
                }

                $licenseeOk = $profileExists;
                if ($licenseeOk && $licenseeVersion !== null && $compatibility === null) {
                    if ($game['licensee_version_min'] !== null && $licenseeVersion < (int)$game['licensee_version_min']) {
                        $licenseeOk = false;
                    }
                    if ($game['licensee_version_max'] !== null && $licenseeVersion > (int)$game['licensee_version_max']) {
                        $licenseeOk = false;
                    }
                }

                $compatible = $profileExists && $extensionOk && $engineOk && $versionOk && $licenseeOk;
                if ($compatible && $exact > 0) {
                    $assessment = ($exact === $imports || ($matchPercent !== null && $matchPercent >= 75.0))
                        ? 'likely' : 'possible';
                    $rank = 1;
                } elseif ($compatible && $imports > 0) {
                    $assessment = 'package_only';
                    $rank = 2;
                } elseif ($compatible) {
                    $assessment = 'compatible';
                    $rank = 3;
                } elseif ($imports > 0) {
                    $assessment = 'conflict';
                    $rank = 4;
                } else {
                    $assessment = 'incompatible';
                    $rank = 5;
                }

                $reasons = [];
                if (!$profileExists) $reasons[] = 'No active game profile';
                if ($profileExists && !$extensionOk) $reasons[] = 'Extension not allowed';
                if ($profileExists && !$engineOk) $reasons[] = 'Engine mismatch';
                if ($profileExists && !$versionOk) $reasons[] = 'Package version outside profile range';
                if ($profileExists && !$licenseeOk) $reasons[] = 'Licensee version outside profile range';
                if ($compatibility !== null) $reasons[] = (string)($compatibility['label'] ?? 'Compatibility rule');

                $rows[] = [
                    'game_id' => $gameId,
                    'game_name' => (string)$game['game_name'],
                    'profile_id' => (int)($game['profile_id'] ?? 0),
                    'profile_name' => (string)($game['profile_name'] ?? ''),
                    'engine_key' => $profileEngine,
                    'compatible' => $compatible,
                    'assessment' => $assessment,
                    'rank' => $rank,
                    'import_count' => $imports,
                    'owner_count' => $owners,
                    'exact_object_matches' => $exact,
                    'unmatched_object_count' => $unmatched,
                    'match_percent' => $matchPercent,
                    'extension_ok' => $extensionOk,
                    'engine_ok' => $engineOk,
                    'version_ok' => $versionOk,
                    'licensee_ok' => $licenseeOk,
                    'compatibility_label' => $compatibility['label'] ?? null,
                    'reason' => implode('; ', $reasons),
                ];
            }

            usort($rows, static function (array $left, array $right): int {
                return ($left['rank'] <=> $right['rank'])
                    ?: ($right['exact_object_matches'] <=> $left['exact_object_matches'])
                    ?: (($right['match_percent'] ?? -1) <=> ($left['match_percent'] ?? -1))
                    ?: ($right['owner_count'] <=> $left['owner_count'])
                    ?: strcasecmp((string)$left['game_name'], (string)$right['game_name']);
            });
            $result[$fileId] = $rows;
        }

        return $result;
    }

    /**
     * Resolve dependency package text to the compact term IDs once per worker,
     * preserving the previous case-insensitive package semantics while allowing
     * every hot dependency lookup to use idx_ue_dependency_required directly.
     *
     * @param array<string,string> $packageNames logical-key => current package name
     * @return array<string,list<array{dependency_key:string,owner_file_id:int,required_object_path:string,game_id:int,game_name:string}>>
     */
    private function dependencyEvidenceForPackages(array $packageNames): array
    {
        if ($packageNames === []) {
            return [];
        }

        [$termIdsByKey, $packageKeyByTermId] = $this->packageTermIds($packageNames);
        $wantedTermIds = [];
        foreach (array_keys($packageNames) as $packageKey) {
            foreach ($termIdsByKey[$packageKey] ?? [] as $termId) {
                $wantedTermIds[$termId] = true;
            }
        }
        if ($wantedTermIds === []) {
            return [];
        }

        $evidence = [];
        foreach (array_chunk(array_keys($wantedTermIds), self::DEPENDENCY_TERM_BATCH_SIZE) as $termIds) {
            $statement = $this->db->prepare(
                'SELECT l.file_id owner_file_id,l.import_index,l.required_package_term_id,'
                . 'CONVERT(object_term.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci required_object_path,'
                . 'owner.game_id,g.name game_name '
                . 'FROM ue_dependency_links l '
                . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
                . 'JOIN ue_terms object_term ON object_term.id=l.required_object_term_id '
                . 'JOIN ue_files owner ON owner.id=l.file_id AND owner.scan_status="verified" '
                . 'JOIN ue_games g ON g.id=owner.game_id '
                . 'WHERE l.required_package_term_id IN ('
                . implode(',', array_fill(0, count($termIds), '?')) . ')'
            );
            $statement->execute($termIds);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $termId = (int)$row['required_package_term_id'];
                $packageKey = $packageKeyByTermId[$termId] ?? null;
                if (!is_string($packageKey) || !isset($packageNames[$packageKey])) {
                    continue;
                }
                $ownerFileId = (int)$row['owner_file_id'];
                $importIndex = (int)$row['import_index'];
                $evidence[$packageKey][] = [
                    'dependency_key' => $ownerFileId . ':' . $importIndex,
                    'owner_file_id' => $ownerFileId,
                    'required_object_path' => (string)$row['required_object_path'],
                    'game_id' => (int)$row['game_id'],
                    'game_name' => (string)$row['game_name'],
                ];
            }
        }
        return $evidence;
    }

    /**
     * @param array<string,string> $packageNames logical-key => current package name
     * @return array{0:array<string,list<int>>,1:array<int,string>}
     */
    private function packageTermIds(array $packageNames): array
    {
        $this->ensureRequiredPackageTermIndex();
        $termIdsByKey = [];
        $packageKeyByTermId = [];

        foreach (array_keys($packageNames) as $packageKey) {
            foreach ($this->requiredPackageTermIdsByKey[$packageKey] ?? [] as $termId) {
                $termIdsByKey[$packageKey][$termId] = $termId;
                $packageKeyByTermId[$termId] = $packageKey;
            }
        }

        // Workers can stay alive while verified metadata is being added. Resolve
        // the exact current package spellings through the hash/length dictionary
        // as well, so a newly introduced package term does not require a restart.
        foreach ($this->resolveExactTermIds(array_values($packageNames)) as $packageKey => $termIds) {
            foreach ($termIds as $termId) {
                $termIdsByKey[$packageKey][$termId] = $termId;
                $packageKeyByTermId[$termId] = $packageKey;
            }
        }

        foreach ($termIdsByKey as $packageKey => $ids) {
            $termIdsByKey[$packageKey] = array_values($ids);
        }
        return [$termIdsByKey, $packageKeyByTermId];
    }

    private function ensureRequiredPackageTermIndex(): void
    {
        if ($this->requiredPackageTermIdsByKey !== null) {
            return;
        }

        // Preserve the compact-only runtime contract before touching projection
        // tables directly. sql() performs the authoritative schema availability
        // check without executing the old derived read source.
        PdoDependencyReadSource::sql($this->db);

        // required_package_term_id is the left-most column of
        // idx_ue_dependency_required. GROUP BY can therefore enumerate distinct
        // package terms from the compact index instead of repeatedly converting
        // term text while scanning dependency rows for every staged file.
        $statement = $this->db->query(
            'SELECT required_package_term_id FROM ue_dependency_links '
            . 'GROUP BY required_package_term_id ORDER BY NULL'
        );
        if ($statement === false) {
            throw new RuntimeException('Could not enumerate compact dependency package terms.');
        }
        $termIds = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $termId = (int)$value;
            if ($termId > 0) {
                $termIds[$termId] = $termId;
            }
        }

        $byKey = [];
        foreach (array_chunk(array_values($termIds), self::TERM_ID_BATCH_SIZE) as $chunk) {
            $terms = $this->db->prepare(
                'SELECT id,CONVERT(value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci value_text '
                . 'FROM ue_terms WHERE id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $terms->execute($chunk);
            while (($row = $terms->fetch(PDO::FETCH_ASSOC)) !== false) {
                $termId = (int)$row['id'];
                $packageKey = $this->key((string)$row['value_text']);
                if ($termId < 1 || $packageKey === '') {
                    continue;
                }
                $byKey[$packageKey][$termId] = $termId;
            }
        }
        foreach ($byKey as $packageKey => $ids) {
            $byKey[$packageKey] = array_values($ids);
        }

        $this->requiredPackageTermIdsByKey = $byKey;
    }

    /**
     * @param list<string> $values
     * @return array<string,list<int>> logical-key => exact term IDs
     */
    private function resolveExactTermIds(array $values): array
    {
        $terms = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $length = strlen($value);
            if ($length > 65535) {
                throw new RuntimeException('Compact package lookup term exceeds 65,535 bytes.');
            }
            $identity = md5($value) . ':' . $length;
            $terms[$identity] = [
                'value' => $value,
                'hash' => md5($value, true),
                'length' => $length,
                'key' => $this->key($value),
            ];
        }
        if ($terms === []) {
            return [];
        }

        $resolved = [];
        foreach (array_chunk(array_values($terms), self::TERM_ID_BATCH_SIZE) as $chunk) {
            $predicates = [];
            $arguments = [];
            $expected = [];
            foreach ($chunk as $term) {
                $predicates[] = '(value_hash=? AND value_length=?)';
                $arguments[] = $term['hash'];
                $arguments[] = $term['length'];
                $expected[bin2hex($term['hash']) . ':' . $term['length']] = $term;
            }
            $statement = $this->db->prepare(
                'SELECT id,value_hash,value_length,value_prefix,is_overflow FROM ue_terms WHERE '
                . implode(' OR ', $predicates)
            );
            $statement->execute($arguments);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $identity = bin2hex((string)$row['value_hash']) . ':' . (int)$row['value_length'];
                $term = $expected[$identity] ?? null;
                if (!is_array($term)) {
                    continue;
                }
                $stored = (string)$row['value_prefix'];
                $value = (string)$term['value'];
                $matches = hash_equals($stored, $value)
                    || ((int)$row['is_overflow'] === 1 && hash_equals($stored, substr($value, 0, 200)));
                if (!$matches) {
                    throw new RuntimeException('Compact package term hash collision or stored-value mismatch.');
                }
                $resolved[(string)$term['key']][] = (int)$row['id'];
            }
        }
        foreach ($resolved as $packageKey => $ids) {
            $resolved[$packageKey] = array_values(array_unique(array_map('intval', $ids)));
        }
        return $resolved;
    }

    private function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
