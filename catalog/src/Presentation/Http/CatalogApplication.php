<?php
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
        $storage = rtrim((string)($config['storage_path'] ?? ''), '/\\');
        $settingsFile = $storage !== ''
            ? $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'resource-limits.json'
            : null;
        JobResourcePolicy::setLimitFile($settingsFile);
        $resourceLimits = new CatalogJobResourceLimitStore($db, $settingsFile);
        JobResourcePolicy::setLimitResolver(
            static fn(string $resourceClass, int $fallback): int => $resourceLimits->resolve($resourceClass, $fallback)
        );

        return new self($config, $db);
    }
}
