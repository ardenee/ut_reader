#!/usr/bin/env php
<?php
/**
 * Purpose: Runs the guarded one-time purge of legacy metadata owned only by retired duplicate file records.
 * Role: CLI maintenance entry point for the final legacy metadata table retirement sequence.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Application\Maintenance\RetiredDuplicateLegacyMetadataPurger;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

try {
    set_time_limit(0);
    $options = getopt('', ['apply::']);
    $apply = trim((string)($options['apply'] ?? ''));

    $config = catalog_config();
    $purger = new RetiredDuplicateLegacyMetadataPurger(catalog_db($config));

    if ($apply === '') {
        $preflight = $purger->preflight();
        $result = [
            'mode' => 'dry-run',
            'confirmation_token' => RetiredDuplicateLegacyMetadataPurger::CONFIRMATION,
            'apply_command' => 'php catalog/bin/purge-retired-duplicate-legacy-metadata.php --apply='
                . RetiredDuplicateLegacyMetadataPurger::CONFIRMATION,
        ] + $preflight;
        fwrite(STDOUT, json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
        exit(!empty($preflight['safe_to_apply']) ? 0 : 2);
    }

    $result = $purger->purge($apply);
    fwrite(STDOUT, json_encode(
        ['mode' => 'apply'] + $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Retired duplicate legacy metadata purge failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
