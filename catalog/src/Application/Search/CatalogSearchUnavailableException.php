<?php
/**
 * Signals that catalogue search cannot currently serve a request.
 *
 * Kept in its own PSR-4 file so callers may reference the exception before the
 * search service itself has been autoloaded.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

final class CatalogSearchUnavailableException extends \RuntimeException
{
}
