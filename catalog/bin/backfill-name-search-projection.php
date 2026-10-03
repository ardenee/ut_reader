#!/usr/bin/env php
<?php
/**
 * Backfill exact compact Name-table search references for existing verified files.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactSearchProjectionWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactTermOverflowWriter;

$limit = 500;
$afterId = 0;
$all = false;
$gameSlug = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=([0-9]+)$/', (string)$argument, $match) === 1) {
        $limit = max(1, min(5000, (int)$match[1]));
    } elseif (preg_match('/^--after-id=([0-9]+)$/', (string)$argument, $match) === 1) {
        $afterId = max(0, (int)$match[1]);
    } elseif (preg_match('/^--game=([^\s]+)$/', (string)$argument, $match) === 1) {
        $gameSlug = trim((string)$match[1]);
    } elseif ((string)$argument === '--all') {
        $all = true;
    }
}

try {
    $application = catalog_bootstrap();
    $db = $application->db;
    $storageRoot = rtrim((string)($application->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
    if ($storageRoot === '') {
        throw new RuntimeException('Catalog storage path is not configured.');
    }

    $schema = $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_name_lookup"'
    );
    if ((int)$schema->fetchColumn() !== 1) {
        throw new RuntimeException('Run php catalog/bin/migrate.php migrate before backfilling Names search.');
    }

    $gameId = null;
    $game = null;
    if ($gameSlug !== null && $gameSlug !== '') {
        $gameStatement = $db->prepare('SELECT id,name,slug FROM ue_games WHERE slug=? LIMIT 1');
        $gameStatement->execute([$gameSlug]);
        $game = $gameStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($game)) {
            throw new RuntimeException('Unknown game slug: ' . $gameSlug);
        }
        $gameId = (int)$game['id'];
    }

    $reader = new BlockedCompressedMetadataReader($db, $storageRoot);
    $writer = new CompactSearchProjectionWriter($db);
    $processed = 0;
    $nameRows = 0;
    $sqlBatches = 0;
    $cursor = $afterId;
    $errors = [];

    $gameWhere = $gameId !== null ? ' AND f.game_id=? ' : ' ';
    do {
        $statement = $db->prepare(
            'SELECT f.id,f.name_count FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
            . 'WHERE f.scan_status="verified" AND f.id>? '
            . $gameWhere
            . 'AND (SELECT COUNT(*) FROM ue_name_lookup n WHERE n.file_id=f.id)<>f.name_count '
            . 'ORDER BY f.id ASC LIMIT ' . $limit
        );
        $arguments = [BlockedCompressedMetadataContainer::FORMAT_VERSION, $cursor];
        if ($gameId !== null) { $arguments[] = $gameId; }
        $statement->execute($arguments);
        $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($files === []) {
            break;
        }

        foreach ($files as $file) {
            $fileId = (int)($file['id'] ?? 0);
            if ($fileId < 1) {
                continue;
            }
            $cursor = $fileId;
            try {
                $expectedNames = max(0, (int)($file['name_count'] ?? 0));
                $names = [];
                for ($start = 0; $start < $expectedNames; $start += 5000) {
                    $page = $reader->page($fileId, 'names', $start, min(5000, $expectedNames - $start));
                    if ($page === []) {
                        throw new RuntimeException(
                            'Names metadata ended at row ' . $start . ' of ' . $expectedNames
                            . ' for file #' . $fileId . '.'
                        );
                    }
                    array_push($names, ...$page);
                }
                if (count($names) !== $expectedNames) {
                    throw new RuntimeException(
                        'Names metadata count mismatch for file #' . $fileId
                        . ': expected ' . $expectedNames . ', found ' . count($names) . '.'
                    );
                }
                $snapshot = ['file' => ['id' => $fileId], 'names' => $names];
                $nameRows += $writer->writeNames($snapshot, $sqlBatches);
                (new CompactTermOverflowWriter($db))->write($snapshot, $sqlBatches);
                $verify = $db->prepare('SELECT COUNT(*) FROM ue_name_lookup WHERE file_id=?');
                $verify->execute([$fileId]);
                if ((int)$verify->fetchColumn() !== $expectedNames) {
                    throw new RuntimeException('Name search projection count still differs after repair for file #' . $fileId . '.');
                }
                $processed++;
            } catch (Throwable $error) {
                $errors[] = [
                    'file_id' => $fileId,
                    'error' => trim($error->getMessage()) ?: get_class($error),
                ];
            }
        }

        if ($errors !== [] || !$all || count($files) < $limit) {
            break;
        }
    } while (true);

    $remainingStatement = $db->prepare(
        'SELECT COUNT(*) FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE f.scan_status="verified" '
        . ($gameId !== null ? 'AND f.game_id=? ' : '')
        . 'AND (SELECT COUNT(*) FROM ue_name_lookup n WHERE n.file_id=f.id)<>f.name_count'
    );
    $remainingArguments = [BlockedCompressedMetadataContainer::FORMAT_VERSION];
    if ($gameId !== null) { $remainingArguments[] = $gameId; }
    $remainingStatement->execute($remainingArguments);
    $remaining = (int)$remainingStatement->fetchColumn();
    $gameOption = $gameSlug !== null && $gameSlug !== '' ? ' --game=' . $gameSlug : '';

    fwrite(STDOUT, json_encode([
        'ok' => $errors === [],
        'game' => $game,
        'format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
        'processed_files' => $processed,
        'name_rows' => $nameRows,
        'sql_batches' => $sqlBatches,
        'last_file_id' => $cursor,
        'remaining_files' => $remaining,
        'errors' => $errors,
        'next_command' => $remaining > 0
            ? ($errors !== []
                ? 'php catalog/bin/backfill-name-search-projection.php --all' . $gameOption
                : 'php catalog/bin/backfill-name-search-projection.php --all' . $gameOption . ' --after-id=' . $cursor)
            : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($errors === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => trim($error->getMessage()) ?: get_class($error),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
