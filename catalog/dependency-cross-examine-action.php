<?php
/**
 * Queue selected exact cross-game dependency providers for verified import into a destination game.
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

    $rawIds = $_POST['source_file_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    // Preserve compatibility with the former one-row action while the UI moves
    // to checkbox-based batch selection.
    if ($rawIds === [] && isset($_POST['source_file_id'])) {
        $rawIds = [$_POST['source_file_id']];
    }
    $sourceFileIds = array_values(array_unique(array_filter(
        array_map(static fn(mixed $value): int => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : 0, $rawIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($sourceFileIds === []) {
        throw new RuntimeException('Select at least one source package.');
    }
    if (count($sourceFileIds) > 500) {
        throw new RuntimeException('No more than 500 source packages may be queued at once.');
    }

    $destinationGameId = filter_input(INPUT_POST, 'destination_game_id', FILTER_VALIDATE_INT);
    if ($destinationGameId === false || $destinationGameId === null) {
        // Former one-row action used target_game_id.
        $destinationGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
    }
    $destinationGameId = $destinationGameId === false || $destinationGameId === null ? 0 : (int)$destinationGameId;

    $reportTargetGameId = filter_input(INPUT_POST, 'report_target_game_id', FILTER_VALIDATE_INT);
    $reportTargetGameId = $reportTargetGameId === false || $reportTargetGameId === null
        ? $destinationGameId
        : (int)$reportTargetGameId;
    $sourceGameId = filter_input(INPUT_POST, 'source_game_id', FILTER_VALIDATE_INT);
    $sourceGameId = $sourceGameId === false || $sourceGameId === null ? 0 : (int)$sourceGameId;
    $limit = filter_input(INPUT_POST, 'limit', FILTER_VALIDATE_INT);
    $limit = $limit === false || $limit === null ? 100 : max(10, min(500, (int)$limit));

    if ($destinationGameId < 1) {
        throw new RuntimeException('Choose a destination game.');
    }

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $service = new CatalogCrossGamePackageCopyService($db, $config);
    $queued = [];
    $failed = [];
    foreach ($sourceFileIds as $sourceFileId) {
        try {
            $queued[] = $service->queue($sourceFileId, $destinationGameId, $userId);
        } catch (Throwable $error) {
            $message = trim($error->getMessage()) ?: 'Could not queue this package.';
            $failed[] = 'file #' . $sourceFileId . ': ' . $message;
        }
    }

    $query = [
        'target_game_id' => max(0, $reportTargetGameId),
        'source_game_id' => max(0, $sourceGameId),
        'limit' => $limit,
    ];

    if ($queued !== []) {
        $targetName = (string)($queued[0]['target_game'] ?? ('game #' . $destinationGameId));
        $jobIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['job_id'] ?? 0),
            $queued
        ), static fn(int $id): bool => $id > 0));
        $jobSummary = $jobIds === []
            ? ''
            : ' Background job' . (count($jobIds) === 1 ? '' : 's') . ': #'
                . implode(', #', array_slice($jobIds, 0, 10))
                . (count($jobIds) > 10 ? ' +' . (count($jobIds) - 10) . ' more' : '') . '.';
        $query['notice'] = 'Queued ' . count($queued) . ' of ' . count($sourceFileIds)
            . ' selected package' . (count($sourceFileIds) === 1 ? '' : 's')
            . ' to ' . $targetName . '.' . $jobSummary;
    }

    if ($failed !== []) {
        $shown = array_slice($failed, 0, 5);
        $query['error'] = count($failed) . ' selected package' . (count($failed) === 1 ? '' : 's')
            . ' could not be queued: ' . implode(' | ', $shown)
            . (count($failed) > count($shown) ? ' | +' . (count($failed) - count($shown)) . ' more.' : '');
    }

    if ($queued === [] && $failed === []) {
        $query['error'] = 'No selected package could be queued.';
    }

    header('Location: dependency-cross-examine.php?' . http_build_query($query), true, 303);
    exit;
} catch (Throwable $error) {
    $reportTargetGameId = isset($reportTargetGameId) ? (int)$reportTargetGameId : 0;
    $sourceGameId = isset($sourceGameId) ? (int)$sourceGameId : 0;
    $limit = isset($limit) ? (int)$limit : 100;
    $query = [
        'target_game_id' => max(0, $reportTargetGameId),
        'source_game_id' => max(0, $sourceGameId),
        'limit' => max(10, min(500, $limit)),
        'error' => trim($error->getMessage()) ?: 'Cross-game package copies could not be queued.',
    ];
    header('Location: dependency-cross-examine.php?' . http_build_query($query), true, 303);
    exit;
}
