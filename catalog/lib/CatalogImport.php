<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/GameProfiles.php';

function catalog_import_detect_game(PDO $db, string $extension): ?array
{
    $extension = catalog_clean_unreal_extension($extension);
    $rows = catalog_all(
        $db,
        'SELECT g.*,p.engine_key profile_engine,p.allowed_extensions_json '
        . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.id'
    );
    foreach ($rows as $row) {
        $extensions = gp_extensions($row);
        if ($extensions === [] || in_array($extension, $extensions, true)) {
            return $row;
        }
    }
    return $rows[0] ?? null;
}

function catalog_import_rebuild_dependencies(
    PDO $db,
    array $config,
    int $fileId,
    ?callable $progress = null,
    ?int &$completedImports = null,
    ?int $totalImports = null
): void {
    $file = catalog_one($db, 'SELECT import_count,package_name FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        return;
    }

    scanner_rebuild_dependencies(
        $db,
        $config,
        $fileId,
        $progress,
        0,
        100,
        'Rebuilding dependencies for ' . (string)$file['package_name']
    );

    $completedImports ??= 0;
    $completedImports += (int)$file['import_count'];
}

function catalog_import_rebuild_game(PDO $db, array $config, int $gameId, ?callable $progress = null): void
{
    scanner_rebuild_game($db, $config, $gameId, $progress, 0, 100);
}

/** @return array{status:string,file_id:int|null,message:string} */
function catalog_import_file(
    PDO $db,
    array $config,
    string $sourcePath,
    string $originalName,
    ?int $preferredGameId = null,
    ?int $uploadedBy = null
): array {
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Import source file missing: ' . $sourcePath);
    }

    $cleanName = scanner_clean_original_filename($originalName);
    $extension = catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION));
    $md5 = md5_file($sourcePath);
    if (!is_string($md5) || $md5 === '') {
        throw new RuntimeException('Could not hash import source file.');
    }

    $duplicate = catalog_one(
        $db,
        'SELECT id,original_name FROM ue_files WHERE md5=? AND scan_status="verified" ORDER BY id LIMIT 1',
        [$md5]
    );
    if ($duplicate) {
        return [
            'status' => 'duplicate_md5',
            'file_id' => (int)$duplicate['id'],
            'message' => 'Duplicate MD5: ' . (string)$duplicate['original_name'],
        ];
    }

    $game = $preferredGameId !== null
        ? catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$preferredGameId])
        : catalog_import_detect_game($db, $extension);
    if (!$game) {
        throw new RuntimeException('Could not detect target game for extension: ' . $extension);
    }

    $sourceRelativePath = scanner_normalize_source_relative_path($originalName);
    if ($sourceRelativePath === '') {
        $sourceRelativePath = $cleanName;
    }

    $result = scanner_scan_uploaded_file(
        $db,
        $config,
        (int)$game['id'],
        $sourcePath,
        $cleanName,
        $uploadedBy,
        true,
        null,
        false,
        ['source_relative_path' => $sourceRelativePath]
    );

    $status = (string)($result[0] ?? 'failed');
    $fileId = isset($result[1]) ? (int)$result[1] : null;
    $message = (string)($result[2] ?? $status);

    if ($status === 'duplicate') {
        $status = 'duplicate_md5';
    } elseif ($status === 'alias') {
        $status = 'verified';
    }

    return [
        'status' => $status,
        'file_id' => $fileId,
        'message' => $message,
    ];
}
