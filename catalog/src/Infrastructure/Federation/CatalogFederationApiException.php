<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Carries an intentional federation API HTTP status alongside a protocol-safe error message.
 * Why: Extracted federation services must preserve the existing endpoint status contracts without embedding response writes.
 * Role: Infrastructure protocol exception translated by thin HTTP API adapters.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use RuntimeException;

final class CatalogFederationApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}
