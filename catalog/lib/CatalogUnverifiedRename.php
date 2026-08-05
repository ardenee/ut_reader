<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/UnverifiedFileManager.php';
require_once __DIR__ . '/CatalogUnverifiedIndex.php';

function catalog_unverified_rename_clean_name(string $requestedName): string
{
    $requestedName = trim($requestedName);
    $cleanName = basename(catalog_clean_unreal_filename($requestedName));
    if ($cleanName === '' || $cleanName === '.' || $cleanName === '..') {
        throw new RuntimeException('Enter a valid filename.');
    }
    if (strcasecmp($cleanName, $requestedName) !== 0) {
        throw new RuntimeException('Enter only a filename, without a folder path or invalid Windows filename characters.');
    }
    if (preg_match('/\.uz(?:2|3)?$/i', $cleanName) === 1) {
        throw new RuntimeException('Enter the decompressed Unreal filename without the .uz, .uz2 or .uz3 queue wrapper.');
    }

    $extension = catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION));
    if ($extension === '' || preg_match('/^[a-z0-9_]{1,16}$/i', $extension) !== 1) {
        throw new RuntimeException('The new filename must include a valid Unreal file extension.');
    }
    if ($extension === 'txt') {
        throw new RuntimeException('.txt is reserved for the queue note sidecar.');
    }
    return $cleanName;
}

function catalog_unverified_rename_queue_name(string $currentQueueName, string $newOriginalName): string
{
    $currentQueueName = basename($currentQueueName);
    $prefix = '';
    if (preg_match('/^(\d{8}_\d{6}_[A-Fa-f0-9]{8}_)/', $currentQueueName, $match) === 1) {
        $prefix = $match[1];
    }

    $wrapper = '';
    if (preg_match('/(\.uz(?:2|3)?)$/i', $currentQueueName, $match) === 1) {
        $wrapper = $match[1];
    }
    return $prefix . $newOriginalName . $wrapper;
}

function catalog_unverified_rename_source_relative(string $sourceRelativePath, string $newOriginalName): string
{
    $sourceRelativePath = scanner_normalize_source_relative_path($sourceRelativePath);
    if ($sourceRelativePath === '') {
        return '';
    }
    $normalized = str_replace('\\', '/', $sourceRelativePath);
    $slash = strrpos($normalized, '/');
    return $slash === false
        ? $newOriginalName
        : substr($normalized, 0, $slash + 1) . $newOriginalName;
}

/**
 * Rename an indexed unverified file without changing its contents or hashes.
 * Redirect wrappers remain physical queue suffixes while original_name becomes
 * the corrected decompressed Unreal filename.
 *
 * @return array{file_id:int,old_name:string,new_name:string,old_queue_name:string,new_queue_name:string,package_name:string}
 */
function catalog_unverified_rename_file(PDO $db, array $config, int $fileId, string $requestedName): array
{
    catalog_unverified_schema_ensure($db);
    if ($fileId < 1) {
        throw new RuntimeException('Invalid unverified file ID.');
    }

    $row = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1', [$fileId]);
    if (!$row) {
        throw new RuntimeException('The unverified staging row no longer exists.');
    }

    $newOriginalName = catalog_unverified_rename_clean_name($requestedName);
    $oldOriginalName = trim((string)($row['original_name'] ?? ''));
    $oldQueueName = basename(trim((string)($row['unverified_queue_name'] ?? '')));
    $queueGameId = (int)($row['unverified_queue_game_id'] ?? 0);
    if ($oldQueueName === '') {
        throw new RuntimeException('The staging row has no physical queue filename.');
    }

    $queueGame = $queueGameId === 0
        ? uvf_bucket_game()
        : catalog_one($db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?', [$queueGameId]);
    if (!$queueGame) {
        throw new RuntimeException('The physical queue game no longer exists.');
    }

    $directory = uvf_unverified_dir($config, $queueGame, false);
    $oldPath = $directory . DIRECTORY_SEPARATOR . $oldQueueName;
    if (!is_file($oldPath) || is_link($oldPath) || !uvf_path_inside($oldPath, $directory)) {
        throw new RuntimeException('The physical staged file is missing or unsafe.');
    }

    $newQueueName = catalog_unverified_rename_queue_name($oldQueueName, $newOriginalName);
    if ($newQueueName === '' || basename($newQueueName) !== $newQueueName) {
        throw new RuntimeException('The corrected queue filename is invalid.');
    }
    if ($oldOriginalName === $newOriginalName && $oldQueueName === $newQueueName) {
        throw new RuntimeException('The file already uses that name.');
    }

    $newPath = $directory . DIRECTORY_SEPARATOR . $newQueueName;
    $caseOnlyPath = strcasecmp($oldPath, $newPath) === 0;
    if (!$caseOnlyPath && file_exists($newPath)) {
        throw new RuntimeException('A staged file with the corrected queue filename already exists.');
    }

    $oldReasonPath = $oldPath . '.txt';
    $newReasonPath = $newPath . '.txt';
    if (!$caseOnlyPath && is_file($oldReasonPath) && file_exists($newReasonPath)) {
        throw new RuntimeException('A queue note already exists for the corrected filename.');
    }

    $newQueueKey = catalog_unverified_queue_key($queueGameId, $newQueueName);
    $collision = catalog_one(
        $db,
        'SELECT id FROM ue_files WHERE unverified_queue_key=? AND id<>? LIMIT 1',
        [$newQueueKey, $fileId]
    );
    if ($collision) {
        throw new RuntimeException('Another staging row already uses the corrected queue filename.');
    }

    $temporaryPath = '';
    $fileMoved = false;
    $reasonMoved = false;
    try {
        if ($oldPath !== $newPath) {
            if ($caseOnlyPath) {
                $temporaryPath = $oldPath . '.rename-' . bin2hex(random_bytes(4));
                if (!@rename($oldPath, $temporaryPath) || !@rename($temporaryPath, $newPath)) {
                    if (is_file($temporaryPath) && !is_file($oldPath)) {
                        @rename($temporaryPath, $oldPath);
                    }
                    throw new RuntimeException('Could not apply the case-only physical filename change.');
                }
            } elseif (!@rename($oldPath, $newPath)) {
                throw new RuntimeException('Could not rename the physical staged file.');
            }
            $fileMoved = true;
        }

        if (is_file($oldReasonPath) && $oldReasonPath !== $newReasonPath) {
            if (!@rename($oldReasonPath, $newReasonPath)) {
                if ($fileMoved && is_file($newPath) && !is_file($oldPath)) {
                    @rename($newPath, $oldPath);
                }
                throw new RuntimeException('The staged file was restored because its queue note could not be renamed.');
            }
            $reasonMoved = true;
        }

        $newSourceRelativePath = catalog_unverified_rename_source_relative(
            (string)($row['source_relative_path'] ?? ''),
            $newOriginalName
        );
        $engine = strtoupper(trim((string)($row['detected_engine_key'] ?? '')));
        $newPackageName = in_array($engine, ['UE4', 'UE5'], true) && $newSourceRelativePath !== ''
            ? scanner_ue_package_name_from_source_relative($newSourceRelativePath)
            : scanner_logical_package_name($newOriginalName);
        if ($newPackageName === '') {
            throw new RuntimeException('The corrected filename does not produce a valid package name.');
        }

        $newRelativePath = catalog_unverified_storage_relative($config, $newPath);
        $newExtension = catalog_clean_unreal_extension((string)pathinfo($newOriginalName, PATHINFO_EXTENSION));
        $renameNote = 'Renamed staged file from ' . ($oldOriginalName !== '' ? $oldOriginalName : $oldQueueName)
            . ' to ' . $newOriginalName . ' on ' . gmdate('Y-m-d H:i:s') . ' UTC.';
        $scanNotes = trim((string)($row['scan_notes'] ?? ''));
        $scanNotes = trim($scanNotes . "\n" . $renameNote);

        $db->beginTransaction();
        try {
            $update = $db->prepare(
                'UPDATE ue_files SET package_name=?,original_name=?,source_relative_path=?,stored_name=?,relative_path=?,extension=?,'
                . 'unverified_queue_key=?,unverified_queue_name=?,scan_notes=? '
                . 'WHERE id=? AND scan_status="unverified"'
            );
            $update->execute([
                $newPackageName,
                $newOriginalName,
                $newSourceRelativePath !== '' ? $newSourceRelativePath : null,
                $newQueueName,
                $newRelativePath,
                $newExtension,
                $newQueueKey,
                $newQueueName,
                $scanNotes,
                $fileId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The staging row changed before the rename could be saved.');
            }

            if (strcasecmp((string)$row['package_name'], $newPackageName) !== 0) {
                $exports = catalog_all($db, 'SELECT id,local_path FROM ue_exports WHERE file_id=?', [$fileId]);
                $updateExport = $db->prepare('UPDATE ue_exports SET full_path=? WHERE id=?');
                foreach ($exports as $export) {
                    $updateExport->execute([
                        scanner_join_path_parts([$newPackageName, (string)$export['local_path']]),
                        (int)$export['id'],
                    ]);
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    } catch (Throwable $error) {
        if ($reasonMoved && is_file($newReasonPath) && !is_file($oldReasonPath)) {
            @rename($newReasonPath, $oldReasonPath);
        }
        if ($fileMoved && is_file($newPath) && !is_file($oldPath)) {
            @rename($newPath, $oldPath);
        }
        throw $error;
    }

    return [
        'file_id' => $fileId,
        'old_name' => $oldOriginalName,
        'new_name' => $newOriginalName,
        'old_queue_name' => $oldQueueName,
        'new_queue_name' => $newQueueName,
        'package_name' => $newPackageName,
    ];
}
