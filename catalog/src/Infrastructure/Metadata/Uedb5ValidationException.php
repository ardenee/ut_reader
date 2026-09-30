<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5ValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
