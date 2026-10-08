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
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

final class PdoUnverifiedGameMatchQuery
{
    private readonly CatalogUnverifiedStagingIndex $staging;
    private readonly CatalogUnverifiedMetadataStore $metadata;

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
     * Resolve dependency evidence from V5 package-key candidates and confirm
     * exact source-shaped package/object identity from UEDB5.
     *
     * @param array<string,string> $packageNames logical-key => current package name
     * @return array<string,list<array{dependency_key:string,owner_file_id:int,required_object_path:string,game_id:int,game_name:string}>>
     */
    private function dependencyEvidenceForPackages(array $packageNames): array
    {
        if ($packageNames === []) {
            return [];
        }

        $predicates = [];
        $args = [];
        $logicalByIdentity = [];
        foreach ($packageNames as $packageKey => $packageName) {
            foreach ([
                Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,
                Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,
            ] as $kind) {
                $binary = Uedb5SqlProjectionContract::classicPackageKeyBinary($packageName, $kind);
                $predicates[] = '(e.required_package_key_kind=? AND e.required_package_key=?)';
                $args[] = $kind;
                $args[] = $binary;
                $logicalByIdentity[$kind . ':' . bin2hex($binary)] = $packageKey;
            }
        }
        if ($predicates === []) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT e.file_id owner_file_id,e.source_index,e.required_package_key_kind,'
            . 'HEX(e.required_package_key) required_package_key_hex,owner.game_id,g.name game_name '
            . 'FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_files owner ON owner.id=e.file_id AND owner.scan_status="verified" '
            . 'JOIN ue_games g ON g.id=owner.game_id '
            . 'WHERE e.source_kind=' . Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT
            . ' AND (' . implode(' OR ', $predicates) . ') '
            . 'ORDER BY e.file_id,e.source_index'
        );
        $statement->execute($args);
        $candidates = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($candidates === []) {
            return [];
        }

        $config = function_exists('catalog_config') ? \catalog_config() : [];
        if (!is_array($config) || trim((string)($config['storage_path'] ?? '')) === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 unverified matching.');
        }
        $reader = new Uedb5ParityV5ReadService($this->db, $config);
        $byFile = [];
        foreach ($candidates as $candidate) {
            $fileId = (int)$candidate['owner_file_id'];
            $byFile[$fileId]['game_id'] = (int)$candidate['game_id'];
            $byFile[$fileId]['game_name'] = (string)$candidate['game_name'];
            $byFile[$fileId]['rows'][(int)$candidate['source_index']] = $candidate;
        }

        $evidence = [];
        foreach ($byFile as $fileId => $entry) {
            $indexes = array_map('intval', array_keys((array)$entry['rows']));
            $details = $reader->dependenciesAtIndexes((int)$entry['game_id'], (int)$fileId, $indexes);
            foreach ((array)$entry['rows'] as $sourceIndex => $candidate) {
                $identity = (int)$candidate['required_package_key_kind'] . ':'
                    . strtolower((string)$candidate['required_package_key_hex']);
                $packageKey = $logicalByIdentity[$identity] ?? null;
                $detail = $details[(int)$sourceIndex] ?? null;
                if (!is_string($packageKey) || !isset($packageNames[$packageKey]) || !is_array($detail)) {
                    continue;
                }
                if ($this->key((string)($detail['required_package'] ?? '')) !== $packageKey) {
                    continue;
                }
                $evidence[$packageKey][] = [
                    'dependency_key' => (int)$fileId . ':' . (int)$sourceIndex,
                    'owner_file_id' => (int)$fileId,
                    'required_object_path' => (string)($detail['required_object_path'] ?? ''),
                    'game_id' => (int)$entry['game_id'],
                    'game_name' => (string)$entry['game_name'],
                ];
            }
        }
        return $evidence;
    }

    private function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
