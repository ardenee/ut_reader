<?php
/** Non-error outcome: valid Unreal package does not match the selected game profile. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use RuntimeException;

final class CatalogProfileMismatchException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
