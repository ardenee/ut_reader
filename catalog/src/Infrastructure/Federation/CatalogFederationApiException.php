<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Carries an intentional federation API HTTP status alongside a protocol-safe error message.
 * Why: Extracted federation services must preserve existing endpoint status and structured-error contracts without embedding response writes.
 * Role: Infrastructure protocol exception translated by thin HTTP API adapters.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use RuntimeException;
use Throwable;

final class CatalogFederationApiException extends RuntimeException
{
    /** @param array<string,mixed> $response */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        ?Throwable $previous = null,
        public readonly array $response = []
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string,mixed> */
    public function responsePayload(): array
    {
        return ['ok' => false, 'error' => $this->getMessage()] + $this->response;
    }
}
