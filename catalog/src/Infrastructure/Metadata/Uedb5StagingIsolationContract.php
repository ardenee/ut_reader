<?php
/**
 * Enforces the pre-cutover UEDB5 staging isolation boundary.
 * V5 may read live catalogue/V4 identity, but may write only V5 staging state.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5StagingIsolationContract
{
    /** @return list<string> */
    public static function allowedWriteTables(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(Uedb5SqlProjectionContract::baselineTables()),
            ['ue_uedb5_migration_status']
        )));
    }

    /** @return list<string> */
    public static function liveReadOnlyTables(): array
    {
        return ['ue_files', 'ue_games', 'ue_file_metadata'];
    }

    /** @return list<string> */
    public static function forbiddenLiveWriteTables(): array
    {
        return [
            'ue_file_metadata',
            'ue_terms',
            'ue_name_lookup',
            'ue_export_lookup',
            'ue_export_path_lookup',
            'ue_legacy_export_identity_lookup',
            'ue_dependency_links',
            'ue_dependency_identity_lookup',
            'ue_search_documents',
        ];
    }

    public static function assertWriteTable(string $table): void
    {
        $table = strtolower(trim($table, " \t\n\r\0\x0B`"));
        if (!in_array($table, self::allowedWriteTables(), true)) {
            throw new RuntimeException(
                'UEDB5 staging may not write non-V5 table: ' . $table
            );
        }
    }

    /** @return array{v4:string,v5:string} */
    public static function assertContainerPathIsolation(
        string $storageRoot,
        int $gameId,
        int $fileId
    ): array {
        $v4 = BlockedCompressedMetadataContainer::path($storageRoot, $gameId, $fileId);
        $v5 = Uedb5MetadataContainer::path($storageRoot, $gameId, $fileId);
        if ($v4 === $v5 || strtolower(pathinfo($v4, PATHINFO_EXTENSION)) !== 'uedb4'
            || strtolower(pathinfo($v5, PATHINFO_EXTENSION)) !== 'uedb5') {
            throw new RuntimeException('UEDB5 staging path is not isolated from live UEDB4 metadata.');
        }
        return ['v4' => $v4, 'v5' => $v5];
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'live_uedb4_registration_is_read_only_during_staging',
            'live_v4_projection_tables_are_read_only_during_staging',
            'staged_sql_writes_use_only_ue_uedb5_tables',
            'uedb5_file_path_must_not_replace_uedb4_file_path',
            'cutover_is_the_only_step_allowed_to_replace_live_registration',
        ];
    }
}
