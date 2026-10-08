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
        return ['ue_files', 'ue_games'];
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
                'UEDB5 runtime may not write non-V5 metadata table: ' . $table
            );
        }
    }

    /** @return array{v4:string,v5:string} */
    public static function assertContainerPathIsolation(
        string $storageRoot,
        int $gameId,
        int $fileId
    ): array {
        $root = rtrim($storageRoot, "\\/");
        if ($root === '' || $gameId < 1 || $fileId < 1) {
            throw new RuntimeException('UEDB5 metadata path requires valid storage, game and file identities.');
        }
        $v4 = $root . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'game-' . $gameId
            . DIRECTORY_SEPARATOR . $fileId . '.uedb4';
        $v5 = Uedb5MetadataContainer::path($storageRoot, $gameId, $fileId);
        if ($v4 === $v5 || strtolower(pathinfo($v4, PATHINFO_EXTENSION)) !== 'uedb4'
            || strtolower(pathinfo($v5, PATHINFO_EXTENSION)) !== 'uedb5') {
            throw new RuntimeException('UEDB5 metadata path collides with a retired UEDB4 path.');
        }
        return ['v4' => $v4, 'v5' => $v5];
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'runtime_metadata_writes_use_only_ue_uedb5_tables',
            'retired_v4_projection_tables_are_never_written',
            'uedb5_file_path_must_not_reuse_retired_uedb4_path',
            'verified_runtime_metadata_is_uedb5_only',
        ];
    }
}
