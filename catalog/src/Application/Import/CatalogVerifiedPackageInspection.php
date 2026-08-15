<?php
/**
 * Immutable data produced by verified-package inspection before persistence.
 *
 * Application code can reason about an inspected package without knowing how
 * PDO, filesystem hashing or generation-specific readers produced the data.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Import;

final readonly class CatalogVerifiedPackageInspection
{
    /**
     * @param array<string,mixed> $game
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $classification
     * @param array<string,mixed> $header
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @param array<string,mixed> $ue4ReaderOptions
     */
    public function __construct(
        public array $game,
        public array $profile,
        public array $classification,
        public array $header,
        public array $names,
        public array $imports,
        public array $exports,
        public array $ue4ReaderOptions,
        public string $submittedOriginalName,
        public string $originalName,
        public string $sourceRelativePath,
        public string $profileEngine,
        public string $readerEngine,
        public string $detectedEngine,
        public string $sourcePackageName,
        public string $packageName,
        public string $extension,
        public int $fileSize,
        public string $md5,
        public string $sha1,
        public string $packageGuid,
        public ?string $scanNotes
    ) {
    }

    public function nameCount(): int
    {
        return count($this->names);
    }

    public function importCount(): int
    {
        return count($this->imports);
    }

    public function exportCount(): int
    {
        return count($this->exports);
    }
}
