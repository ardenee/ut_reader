<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

use PDO;

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

    public static function boot(): self
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Preserve current controller behaviour while giving future deployment
        // policy a single location instead of repeating these directives.
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');

        $config = \catalog_config();
        $db = \catalog_db($config);

        return new self($config, $db);
    }
}
