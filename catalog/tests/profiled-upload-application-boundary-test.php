<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies that profiled-upload orchestration is independent of PDO/config while preserving batch behavior.
 * Why: This use case previously leaked persistence and scanner dependencies into Application.
 * Role: Architecture and behavior regression test for the profiled-upload application boundary.
 * Audit: Keep fakes at the Application ports; do not turn this back into a source-text implementation test.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Application\Upload\Contract\ProfiledUploadGameCatalog;
use UnrealDb\Catalog\Application\Upload\Contract\UploadFailureLogger;
use UnrealDb\Catalog\Application\Upload\ProfiledUploadService;

function profiled_boundary_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ProfiledBoundaryGames implements ProfiledUploadGameCatalog
{
    public function __construct(private readonly ?string $slug)
    {
    }

    public function slug(int $gameId): ?string
    {
        return $gameId > 0 ? $this->slug : null;
    }
}

final class ProfiledBoundaryImporter implements CatalogPackageImporter
{
    public int $imports = 0;
    public int $preserved = 0;
    public bool $fail = false;
    public bool $existingAlias = false;

    public function import(
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile,
        ?callable $progress
    ): array {
        $this->imports++;
        if ($this->fail) {
            throw new RuntimeException('synthetic import failure');
        }
        if ($this->existingAlias) {
            return ['alias', 42, 'Alias', [], ['alias_already_exists' => true]];
        }
        return ['imported', 42, 'Imported', []];
    }

    public function preserveFailedUpload(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason
    ): void {
        $this->preserved++;
        profiled_boundary_expect($gameSlug === 'ut2004', 'Failed upload received the wrong game slug.');
        profiled_boundary_expect($reason === 'synthetic import failure', 'Failed upload received the wrong failure reason.');
    }
}

final class ProfiledBoundaryLogger implements UploadFailureLogger
{
    public int $calls = 0;

    public function log(string $filename, Throwable $exception): void
    {
        $this->calls++;
    }
}

$temporary = tempnam(sys_get_temp_dir(), 'ue-profiled-boundary-');
profiled_boundary_expect(is_string($temporary), 'Could not create profiled-upload test file.');
file_put_contents($temporary, 'test');

try {
    $importer = new ProfiledBoundaryImporter();
    $logger = new ProfiledBoundaryLogger();
    $service = new ProfiledUploadService(new ProfiledBoundaryGames('ut2004'), $importer, $logger);
    $files = [
        'tmp_name' => [$temporary],
        'name' => ['Example.utx'],
        'error' => [UPLOAD_ERR_OK],
    ];

    $result = $service->handle(2, true, $files, 7, null);
    profiled_boundary_expect($result['ok'] === 1 && $result['failed'] === 0, 'Successful import counts changed.');
    profiled_boundary_expect($importer->imports === 1, 'Application service did not invoke the package-import port exactly once.');

    $importer->existingAlias = true;
    $aliasResult = $service->handle(2, true, $files, 7, null);
    profiled_boundary_expect($aliasResult['duplicate'] === 1 && $aliasResult['ok'] === 0, 'Existing alias classification changed.');
    profiled_boundary_expect(!array_key_exists('alias_already_exists', $aliasResult['messages'][0] ?? []), 'Internal alias state leaked into the upload result.');
    $importer->existingAlias = false;

    $importer->fail = true;
    $result = $service->handle(2, true, $files, 7, null);
    profiled_boundary_expect($result['failed'] === 1, 'Failed import count changed.');
    profiled_boundary_expect($importer->preserved === 1, 'Failed upload was not delegated for preservation.');
    profiled_boundary_expect($logger->calls === 1, 'Failed upload was not sent to the failure-log port.');

    try {
        (new ProfiledUploadService(new ProfiledBoundaryGames(null), $importer, $logger))
            ->handle(2, true, $files, 7, null);
        throw new RuntimeException('Missing game did not fail.');
    } catch (RuntimeException $error) {
        profiled_boundary_expect($error->getMessage() === 'Game not found', 'Missing-game behavior changed.');
    }
} finally {
    @unlink($temporary);
}

echo "Profiled upload application boundary tests passed.\n";
