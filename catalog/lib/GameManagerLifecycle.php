<?php
declare(strict_types=1);

/**
 * Long-running game reset/delete helpers used by game-manager.php.
 *
 * The existing reset implementation remains responsible for deleting managed
 * game storage and ordinary game-owned ue_files rows. These helpers add the
 * database-backed unverified rows, retained PAK metadata, full game deletion,
 * and post-delete table optimisation.
 */

function gm_lifecycle_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/** @return list<string> */
function gm_lifecycle_optimise_table_list(bool $deleteGame): array
{
    $tables = [
        'ue_asset_registry_tags',
        'ue_asset_registry_dependencies',
        'ue_asset_registry_assets',
        'ue_dependencies',
        'ue_exports',
        'ue_imports',
        'ue_names',
        'ue_external_mirror_jobs',
        'ue_external_download_links',
        'ue_file_locations',
        'ue_file_package_aliases',
        'ue_pak_entries',
        'ue_pak_archives',
        'ue_files',
    ];

    if ($deleteGame) {
        $tables[] = 'ue_base_game_files';
        $tables[] = 'ue_sources';
        $tables[] = 'ue_federation_peer_files';
        $tables[] = 'ue_games';
    }

    return $tables;
}

/**
 * @param list<string> $tables
 * @return array{optimised:list<string>,failed:array<string,string>}
 */
function gm_lifecycle_optimise_tables(
    PDO $db,
    array $tables,
    ?callable $progress,
    int $startPercent,
    int $endPercent
): array {
    $existing = [];
    foreach ($tables as $table) {
        if (gm_lifecycle_table_exists($db, $table)) {
            $existing[] = $table;
        }
    }

    $total = max(1, count($existing));
    $optimised = [];
    $failed = [];
    foreach ($existing as $index => $table) {
        $before = $startPercent + (int)floor((($endPercent - $startPercent) * $index) / $total);
        gm_emit(
            $progress,
            'optimise',
            $index,
            $total,
            $before,
            'Optimising database table ' . ($index + 1) . '/' . $total . ': ' . $table
        );

        try {
            $statement = $db->query('OPTIMIZE TABLE `' . str_replace('`', '``', $table) . '`');
            if ($statement === false) {
                throw new RuntimeException('OPTIMIZE TABLE returned no result.');
            }

            $reportedErrors = [];
            do {
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $messageType = strtolower(trim((string)($row['Msg_type'] ?? $row['msg_type'] ?? '')));
                    if ($messageType !== 'error') {
                        continue;
                    }
                    $messageText = trim((string)($row['Msg_text'] ?? $row['msg_text'] ?? 'Unknown database optimisation error.'));
                    $reportedErrors[] = $messageText !== '' ? $messageText : 'Unknown database optimisation error.';
                }
            } while ($statement->nextRowset());
            $statement->closeCursor();

            if ($reportedErrors !== []) {
                throw new RuntimeException(implode('; ', array_unique($reportedErrors)));
            }
            $optimised[] = $table;
        } catch (Throwable $error) {
            $failed[$table] = $error->getMessage();
            error_log('[UnrealDB game lifecycle] OPTIMIZE TABLE ' . $table . ' failed: ' . $error->getMessage());
        }
    }

    gm_emit(
        $progress,
        'optimise',
        count($existing),
        $total,
        $endPercent,
        $failed === []
            ? 'Database table optimisation complete.'
            : 'Database optimisation completed with ' . count($failed) . ' warning(s).'
    );

    return ['optimised' => $optimised, 'failed' => $failed];
}

/** @return list<array{id:int,relative_path:string,file_size:int}> */
function gm_lifecycle_unverified_rows(PDO $db, int $gameId): array
{
    return array_map(
        static fn(array $row): array => [
            'id' => (int)$row['id'],
            'relative_path' => (string)($row['relative_path'] ?? ''),
            'file_size' => (int)($row['file_size'] ?? 0),
        ],
        catalog_all(
            $db,
            'SELECT id,relative_path,file_size FROM ue_files '
            . 'WHERE game_id IS NULL AND scan_status="unverified" '
            . 'AND unverified_queue_game_id=? ORDER BY id',
            [$gameId]
        )
    );
}

function gm_lifecycle_remove_staged_storage(array $config, array $rows): int
{
    $storageRoot = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
    $catalogRoot = realpath(__DIR__ . '/..');
    if ($storageRoot === false || $catalogRoot === false) {
        return 0;
    }

    $storagePrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $removed = 0;
    foreach ($rows as $row) {
        $relative = ltrim(str_replace('\\', '/', (string)($row['relative_path'] ?? '')), '/');
        if ($relative === '') {
            continue;
        }

        $candidate = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, $storagePrefix)) {
            continue;
        }

        if (!@unlink($resolved)) {
            throw new RuntimeException('Could not remove staged game file: ' . $resolved);
        }
        $removed++;

        $note = $resolved . '.txt';
        if (is_file($note) && str_starts_with($note, $storagePrefix) && !@unlink($note)) {
            throw new RuntimeException('Could not remove staged game-file note: ' . $note);
        }
    }

    return $removed;
}

/**
 * Run the established reset, then remove game-associated staging rows and PAK
 * archive records that are intentionally separate from ordinary ue_files rows.
 *
 * @return array<string,mixed>
 */
function gm_lifecycle_cleanup_game(
    PDO $db,
    array $config,
    int $gameId,
    ?callable $progress,
    int $startPercent,
    int $endPercent
): array {
    $unverifiedRows = gm_lifecycle_unverified_rows($db, $gameId);
    $unverifiedBytes = array_sum(array_column($unverifiedRows, 'file_size'));

    $pakCount = 0;
    $pakBytes = 0;
    if (gm_lifecycle_table_exists($db, 'ue_pak_archives')) {
        $pak = catalog_one(
            $db,
            'SELECT COUNT(*) archive_count,COALESCE(SUM(file_size),0) total_size '
            . 'FROM ue_pak_archives WHERE game_id=?',
            [$gameId]
        ) ?: [];
        $pakCount = (int)($pak['archive_count'] ?? 0);
        $pakBytes = (int)($pak['total_size'] ?? 0);
    }

    $innerEnd = max($startPercent, $endPercent - 8);
    $mappedProgress = $progress === null ? null : static function (array $state) use (
        $progress,
        $startPercent,
        $innerEnd
    ): void {
        $innerPercent = max(0, min(100, (int)($state['percent'] ?? 0)));
        $mapped = $startPercent + (int)floor((($innerEnd - $startPercent) * $innerPercent) / 100);
        gm_emit(
            $progress,
            (string)($state['stage'] ?? 'cleanup'),
            (int)($state['done'] ?? 0),
            max(1, (int)($state['total'] ?? 1)),
            $mapped,
            (string)($state['message'] ?? 'Removing game files…')
        );
    };

    $result = gm_reset_game_files($db, $config, $gameId, $mappedProgress);

    gm_emit(
        $progress,
        'database_cleanup',
        0,
        max(1, count($unverifiedRows) + $pakCount),
        $innerEnd + 1,
        'Removing retained PAK records and game-associated staging rows…'
    );

    $stagedFilesRemoved = gm_lifecycle_remove_staged_storage($config, $unverifiedRows);
    $extraRecordsRemoved = 0;
    $pakRecordsRemoved = 0;

    $db->beginTransaction();
    try {
        if (gm_lifecycle_table_exists($db, 'ue_pak_archives')) {
            $stmt = $db->prepare('DELETE FROM ue_pak_archives WHERE game_id=?');
            $stmt->execute([$gameId]);
            $pakRecordsRemoved = $stmt->rowCount();
        }

        $ids = array_column($unverifiedRows, 'id');
        foreach (array_chunk($ids, 100) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $stmt = $db->prepare(
                'DELETE FROM ue_files WHERE id IN ('
                . implode(',', array_fill(0, count($chunk), '?'))
                . ')'
            );
            $stmt->execute($chunk);
            $extraRecordsRemoved += $stmt->rowCount();
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    gm_emit(
        $progress,
        'database_cleanup',
        count($unverifiedRows) + $pakCount,
        max(1, count($unverifiedRows) + $pakCount),
        $endPercent,
        'All game file, PAK, and staging records have been removed.'
    );

    $result['catalog_records'] = (int)$result['catalog_records'] + $extraRecordsRemoved;
    $result['stored_files'] = (int)$result['stored_files'] + $stagedFilesRemoved;
    $result['total_size'] = (int)$result['total_size'] + $unverifiedBytes + $pakBytes;
    $result['unverified_records'] = $extraRecordsRemoved;
    $result['pak_archives'] = $pakRecordsRemoved;

    return $result;
}

/** @return array<string,mixed> */
function gm_lifecycle_reset_game(PDO $db, array $config, int $gameId, ?callable $progress = null): array
{
    $result = gm_lifecycle_cleanup_game($db, $config, $gameId, $progress, 1, 78);
    $optimise = gm_lifecycle_optimise_tables(
        $db,
        gm_lifecycle_optimise_table_list(false),
        $progress,
        80,
        99
    );
    $result['optimised_tables'] = $optimise['optimised'];
    $result['optimise_failures'] = $optimise['failed'];
    gm_emit($progress, 'done', 1, 1, 100, 'Game reset and database optimisation complete.');
    return $result;
}

/** @return array<string,mixed> */
function gm_lifecycle_delete_game(PDO $db, array $config, int $gameId, ?callable $progress = null): array
{
    $game = catalog_one(
        $db,
        'SELECT g.id,g.name,g.slug,'
        . '(SELECT COUNT(*) FROM ue_sources s WHERE s.game_id=g.id) source_count '
        . 'FROM ue_games g WHERE g.id=?',
        [$gameId]
    );
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    $result = gm_lifecycle_cleanup_game($db, $config, $gameId, $progress, 1, 68);
    gm_emit($progress, 'delete_game', 0, 1, 70, 'Deleting game configuration and source definitions…');

    $baseGameRows = 0;
    $db->beginTransaction();
    try {
        if (gm_lifecycle_table_exists($db, 'ue_base_game_files')) {
            $stmt = $db->prepare('DELETE FROM ue_base_game_files WHERE game_id=?');
            $stmt->execute([$gameId]);
            $baseGameRows = $stmt->rowCount();
        }

        $stmt = $db->prepare('DELETE FROM ue_games WHERE id=?');
        $stmt->execute([$gameId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The game could not be deleted.');
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    gm_emit($progress, 'delete_game', 1, 1, 76, 'Game configuration deleted.');
    $optimise = gm_lifecycle_optimise_tables(
        $db,
        gm_lifecycle_optimise_table_list(true),
        $progress,
        78,
        99
    );

    $result['deleted_game_id'] = (int)$game['id'];
    $result['game_name'] = (string)$game['name'];
    $result['sources'] = (int)$game['source_count'];
    $result['base_game_rows'] = $baseGameRows;
    $result['optimised_tables'] = $optimise['optimised'];
    $result['optimise_failures'] = $optimise['failed'];
    gm_emit($progress, 'done', 1, 1, 100, 'Game deletion and database optimisation complete.');
    return $result;
}
