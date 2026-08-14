<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified;

interface CatalogUnverifiedImporter
{
    /**
     * @param array<string,mixed> $source
     * @param callable(string,int,string):void|null $emit
     * @return array<string,mixed>
     */
    public function import(
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowProfileOverride,
        ?callable $emit = null
    ): array;

    /**
     * @param array<string,mixed> $source
     * @param callable(string,int,string):void|null $emit
     * @return array<string,mixed>
     */
    public function importExactCompatibleGames(
        array $source,
        ?int $userId,
        ?callable $emit = null
    ): array;
}
