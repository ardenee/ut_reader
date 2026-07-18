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

    public static function boot(bool $startSession = true): self
    {
        \catalog_apply_runtime_safeguards();
        if ($startSession) {
            \catalog_start_session();
        }

        $config = \catalog_config();
        $db = \catalog_db($config);

        return new self($config, $db);
    }
}
