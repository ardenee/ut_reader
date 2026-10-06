<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;

/** Bounded staged-SQL impact proof for the UT4/UE4 VerifyImport transition. */
final class PdoUe4VerifyImportImpactQuery
{
    private const CHUNK = 250;
    private const LEGACY_UT4_POLICY = 'ue4-4.27.2-release-classic-package';
    private const READER_GATE_VERSIONS = [325,335,364,383,443,458,484,503,506,507,509,510];

    public function __construct(private readonly PDO $db) {}

    /**
     * @param list<int> $candidateFileIds
     * @return array{reasons_by_file:array<int,array<string,true>>,reason_counts:array<string,int>,total:int,candidate_count:int}
     */
    public function run(array $candidateFileIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $candidateFileIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['reasons_by_file'=>[],'reason_counts'=>[],'total'=>0,'candidate_count'=>0];
        }
        $rows = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT v.file_id,v.source_policy,f.package_version,f.licensee_version,'
                . 'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.source_kind=? AND e.required_object_key IS NOT NULL) has_object_edges,'
                . 'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.source_kind=? AND e.required_object_key IS NOT NULL AND e.outcome IN (?,?)) has_changeable_edges,'
                . 'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e JOIN ue_export_path_lookup x ON x.file_id=e.resolved_file_id AND x.export_index=e.resolved_object_index '
                . 'WHERE e.file_id=v.file_id AND e.source_kind=? AND e.required_object_key IS NOT NULL AND e.outcome=? AND (x.object_flags & 1)=0) has_resolved_private '
                . 'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
                . 'JOIN ue_games g ON g.id=v.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                . 'WHERE v.file_id IN (' . $in . ') AND f.scan_status="verified" AND UPPER(COALESCE(p.engine_key,""))="UE4"';
            $args = [
                Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,
                Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,
                Uedb5SqlProjectionContract::OUTCOME_MISSING,
                Uedb5SqlProjectionContract::OUTCOME_UNRESOLVED,
                Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,
                Uedb5SqlProjectionContract::OUTCOME_RESOLVED,
                ...$chunk,
            ];
            $statement = $this->db->prepare($sql);
            $statement->execute($args);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $row;
            }
        }
        $reasons = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['file_id'];
            $sourcePolicy = (string)$row['source_policy'];
            $version = (int)($row['package_version'] ?? 0);
            $licensee = (int)($row['licensee_version'] ?? 0);
            $hasObjectEdges = (int)$row['has_object_edges'] === 1;

            if ($sourcePolicy === self::LEGACY_UT4_POLICY) {
                if ($licensee === 0 && in_array($version, self::READER_GATE_VERSIONS, true)) {
                    $reasons[$fileId]['ue4_ut4_reader_gate_pass1_reparse'] = true;
                    continue;
                }
                if ($version > 510 || $version <= 0) {
                    // SQL cannot distinguish an old unversioned effective-v522 row
                    // from a genuinely unsupported explicit package. The bounded
                    // UEDB5 summary diagnostic resolves that distinction.
                    $reasons[$fileId]['ue4_ut4_source_profile_review_required'] = true;
                    continue;
                }
                if ($version >= 214 && $version <= 510 && $licensee === 0) {
                    $reasons[$fileId]['ue4_ut4_source_policy_refresh_required'] = true;
                    if ($hasObjectEdges
                        && ((int)$row['has_changeable_edges'] === 1
                            || (int)$row['has_resolved_private'] === 1)) {
                        $reasons[$fileId]['ue4_ut4_verifyimport_outcome_change'] = true;
                    }
                    continue;
                }
                if ($hasObjectEdges) {
                    $reasons[$fileId]['ue4_source_implementation_unavailable'] = true;
                }
                continue;
            }

            if ($sourcePolicy !== Uedb5Ut4SnapshotBuilder::SOURCE_POLICY
                || $version < 214 || $version > 510 || $licensee !== 0) {
                if ($hasObjectEdges) {
                    $reasons[$fileId]['ue4_source_implementation_unavailable'] = true;
                }
                continue;
            }
            if ($hasObjectEdges
                && ((int)$row['has_changeable_edges'] === 1 || (int)$row['has_resolved_private'] === 1)) {
                $reasons[$fileId]['ue4_ut4_verifyimport_outcome_change'] = true;
            }
        }
        $counts = [];
        foreach ($reasons as $set) {
            foreach (array_keys($set) as $reason) {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_STRING);
        return ['reasons_by_file'=>$reasons,'reason_counts'=>$counts,'total'=>count($reasons),'candidate_count'=>count($ids)];
    }
}
