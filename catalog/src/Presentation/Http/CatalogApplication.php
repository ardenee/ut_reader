<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `CatalogApplication` for catalog application.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobResourceLimitStore;

final class CatalogApplication
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        public readonly array $config,
        public readonly PDO $db
    ) {
    }

    public static function boot(bool $startSession = true): self
    {
        \catalog_apply_runtime_safeguards();
        if ($startSession) {
            \catalog_start_session();
        }

        $config = \catalog_config();
        $db = \catalog_db($config);
        $resourceLimits = new CatalogJobResourceLimitStore($db);
        JobResourcePolicy::setLimitResolver(
            static fn(string $resourceClass, int $fallback): int => $resourceLimits->resolve($resourceClass, $fallback)
        );

        return new self($config, $db);
    }
}
