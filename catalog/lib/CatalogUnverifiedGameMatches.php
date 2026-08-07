<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog unverified game matches.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogUnverifiedIndex.php';

/**
 * Rank configured games for one staged file.
 *
 * @return list<array<string,mixed>>
 */
function catalog_unverified_game_matches_v2(PDO $db, int $fileId): array
{
    $matches = catalog_unverified_game_matches_bulk($db, [$fileId]);
    return $matches[$fileId] ?? [];
}

/**
 * Rank configured games for multiple staged files without per-file queries.
 *
 * @param list<int> $fileIds
 * @return array<int,list<array<string,mixed>>>
 */
function catalog_unverified_game_matches_bulk(PDO $db, array $fileIds): array
{
    catalog_unverified_schema_ensure($db);

    $fileIds = array_values(array_unique(array_filter(
        array_map('intval', $fileIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($fileIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $files = catalog_all(
        $db,
        'SELECT id,package_name,extension,detected_engine_key,detected_package_version,detected_licensee_version'
        . ' FROM ue_files'
        . ' WHERE scan_status="unverified" AND id IN (' . $placeholders . ')',
        $fileIds
    );
    if ($files === []) {
        return [];
    }

    $filesById = [];
    foreach ($files as $file) {
        $filesById[(int)$file['id']] = $file;
    }
    $fileIds = array_keys($filesById);
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));

    $evidenceRows = catalog_all(
        $db,
        'SELECT queued.id file_id,g.id game_id,g.name game_name,'
        . ' COUNT(DISTINCT d.id) import_count,'
        . ' COUNT(DISTINCT d.file_id) owner_count,'
        . ' COUNT(DISTINCT CASE WHEN queued_export.id IS NOT NULL THEN d.id END) exact_object_matches'
        . ' FROM ue_files queued'
        . ' JOIN ue_dependencies d ON d.required_package=queued.package_name'
        . ' JOIN ue_files owner ON owner.id=d.file_id AND owner.scan_status="verified"'
        . ' JOIN ue_games g ON g.id=owner.game_id'
        . ' LEFT JOIN ue_exports queued_export'
        . ' ON queued_export.file_id=queued.id AND queued_export.full_path=d.required_object_path'
        . ' WHERE queued.scan_status="unverified" AND queued.id IN (' . $placeholders . ')'
        . ' GROUP BY queued.id,g.id,g.name',
        $fileIds
    );

    $evidenceByFile = [];
    foreach ($evidenceRows as $row) {
        $evidenceByFile[(int)$row['file_id']][(int)$row['game_id']] = $row;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id game_id,g.name game_name,'
        . ' p.id profile_id,p.profile_name,p.engine_key,p.allowed_extensions_json,p.compatibility_rules_json,'
        . ' p.package_version_min,p.package_version_max,p.licensee_version_min,p.licensee_version_max,p.confidence_policy'
        . ' FROM ue_games g'
        . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' ORDER BY g.name'
    );

    $result = [];
    foreach ($filesById as $fileId => $file) {
        $detectedEngine = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        $extension = catalog_clean_unreal_extension((string)$file['extension']);
        $packageVersion = $file['detected_package_version'] !== null ? (int)$file['detected_package_version'] : null;
        $licenseeVersion = $file['detected_licensee_version'] !== null ? (int)$file['detected_licensee_version'] : null;
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
            $allowedExtensions = $profileExists ? gp_extensions($game) : [];
            $extensionOk = $profileExists && ($allowedExtensions === [] || in_array($extension, $allowedExtensions, true));
            $compatibility = $profileExists
                ? gp_compatibility_for_file($game, $extension, $packageVersion, $licenseeVersion, $detectedEngine)
                : null;
            $engineOk = $profileExists && ($profileEngine === $detectedEngine || $compatibility !== null);

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
                $assessment = ($exact === $imports || ($matchPercent !== null && $matchPercent >= 75.0)) ? 'likely' : 'possible';
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
            if (!$profileExists) {
                $reasons[] = 'No active game profile';
            }
            if ($profileExists && !$extensionOk) {
                $reasons[] = 'Extension not allowed';
            }
            if ($profileExists && !$engineOk) {
                $reasons[] = 'Engine mismatch';
            }
            if ($profileExists && !$versionOk) {
                $reasons[] = 'Package version outside profile range';
            }
            if ($profileExists && !$licenseeOk) {
                $reasons[] = 'Licensee version outside profile range';
            }
            if ($compatibility !== null) {
                $reasons[] = (string)($compatibility['label'] ?? 'Compatibility rule');
            }

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
