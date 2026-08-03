#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

try {
    $gameId = 0;
    foreach (array_slice($argv, 1) as $argument) {
        $argument = trim((string)$argument);
        if (in_array($argument, ['--help', '-h', 'help'], true)) {
            fwrite(STDOUT, "Usage: php catalog/bin/rebuild-game-catalog-stats.php [--game-id=ID]\n");
            exit(0);
        }
        if (str_starts_with($argument, '--game-id=')) {
            $gameId = max(0, (int)substr($argument, strlen('--game-id=')));
            continue;
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    $db = catalog_db(catalog_config());
    $stats = new PdoGameCatalogStats($db);
    if (!$stats->available()) {
        throw new RuntimeException('ue_game_catalog_stats is unavailable. Run database migrations first.');
    }

    $startedAt = microtime(true);
    if ($gameId > 0) {
        $result = $stats->rebuildGame($gameId);
        if ($result === null) {
            throw new RuntimeException('Game #' . $gameId . ' was not rebuilt. It may not exist or its rebuild lock is busy.');
        }
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fwrite(STDOUT, sprintf("Rebuilt game #%d statistics in %.2fs.\n", $gameId, microtime(true) - $startedAt));
        exit(0);
    }

    $rebuilt = $stats->rebuildAll();
    fwrite(STDOUT, sprintf(
        "Rebuilt %d game statistics row(s) in %.2fs.\n",
        $rebuilt,
        microtime(true) - $startedAt
    ));
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Game statistics rebuild failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
