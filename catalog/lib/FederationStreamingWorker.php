<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility helpers for the federation streaming worker entry point.
 * Why: Streaming mode should use the same transactional claim and transfer implementation as the standard worker.
 * Role: Thin legacy facade over namespaced federation transfer services.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferClient;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferWorker;
use UnrealDb\Catalog\Infrastructure\Federation\PdoFederationTransferJobStore;

function federation_streaming_claim_transfer(PDO $db): ?array
{
    return (new PdoFederationTransferJobStore($db))->claimTransfer();
}

function federation_streaming_transfer_limit(PDO $db): int
{
    return (new CatalogFederationTransferClient($db))->maxTransferBytes();
}

function federation_streaming_run_one_transfer(PDO $db, array $config): array
{
    return (new CatalogFederationTransferWorker($db, $config))->runOne();
}
