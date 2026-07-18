<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload\Contract;

use Throwable;

/** Application port for durable upload failure diagnostics. */
interface UploadFailureLogger
{
    public function log(string $filename, Throwable $exception): void;
}
