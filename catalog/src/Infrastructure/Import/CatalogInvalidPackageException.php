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
}
