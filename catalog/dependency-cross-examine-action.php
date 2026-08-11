<?php
/**
 * Queue one exact cross-game dependency provider for verified import into the target game.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Unverified\CatalogCrossGamePackageCopyService;

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        http_response_code(403);
        exit('Administrator login is required.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    catalog_check_csrf('dependency-cross-examine');

    $sourceFileId = filter_input(INPUT_POST, 'source_file_id', FILTER_VALIDATE_INT);
    $targetGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
    $sourceGameId = filter_input(INPUT_POST, 'source_game_id', FILTER_VALIDATE_INT);
    $sourceFileId = $sourceFileId === false || $sourceFileId === null ? 0 : (int)$sourceFileId;
    $targetGameId = $targetGameId === false || $targetGameId === null ? 0 : (int)$targetGameId;
    $sourceGameId = $sourceGameId === false || $sourceGameId === null ? 0 : (int)$sourceGameId;
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($sourceFileId < 1 || $targetGameId < 1) {
        throw new RuntimeException('A source file and target game are required.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $result = (new CatalogCrossGamePackageCopyService($db, $config))->queue(
        $sourceFileId,
        $targetGameId,
        $userId
    );

    $notice = 'Queued ' . (string)$result['original_name'] . ' from '
        . (string)$result['source_game'] . ' to ' . (string)$result['target_game']
        . ' as background job #' . (int)$result['job_id'] . '.';
    $query = [
        'target_game_id' => $targetGameId,
        'source_game_id' => max(0, $sourceGameId),
        'notice' => $notice,
    ];
    header('Location: dependency-cross-examine.php?' . http_build_query($query), true, 303);
    exit;
} catch (Throwable $error) {
    $targetGameId = isset($targetGameId) ? (int)$targetGameId : 0;
    $sourceGameId = isset($sourceGameId) ? (int)$sourceGameId : 0;
    $query = [
        'target_game_id' => max(0, $targetGameId),
        'source_game_id' => max(0, $sourceGameId),
        'error' => trim($error->getMessage()) ?: 'Cross-game package copy could not be queued.',
    ];
    header('Location: dependency-cross-examine.php?' . http_build_query($query), true, 303);
    exit;
}
