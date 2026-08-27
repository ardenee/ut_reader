<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Marks package-content/profile validation failures that prove a catalogued package is not valid for import.
 * Why: Full Sync may safely remove a verified row only for authoritative package-validation failures, never for
 *      transient database, filesystem, worker, or infrastructure exceptions.
 * Role: Import-layer validation exception used by maintenance recovery policy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use RuntimeException;

final class CatalogInvalidPackageException extends RuntimeException
{
    /** @param array<string,mixed> $validationArguments */
    public function __construct(
        string $message,
        private readonly string $validationCode = '',
        private readonly array $validationArguments = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function validationCode(): string
    {
        return $this->validationCode;
    }

    /** @return array<string,mixed> */
    public function validationArguments(): array
    {
        return $this->validationArguments;
    }
}
