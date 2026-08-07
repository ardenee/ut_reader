<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `ProfiledUploadService` for profiled upload service.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Application\Upload\Contract\UploadFailureLogger;

/**
 * Application use case for a profile-targeted package upload batch.
 *
 * HTTP/session/CSRF handling remains in the page controller. Package parsing,
 * physical storage, and failed-file preservation remain behind the importer port.
 * Result formatting and concise error text are stable shared contracts.
 */
final class ProfiledUploadService
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly CatalogPackageImporter $importer,
        private readonly ?UploadFailureLogger $failureLogger = null
    ) {
    }

    /**
     * @param array<string, mixed> $uploadedFiles
     * @return array{ok:int,duplicate:int,failed:int,messages:list<array<string, mixed>>}
     */
    public function handle(
        int $gameId,
        bool $strictProfile,
        array $uploadedFiles,
        ?int $userId,
        ?callable $progress
    ): array {
        $game = \catalog_one($this->db, 'SELECT slug FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new \RuntimeException('Game not found');
        }

        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        $messages = [];
        $temporaryPaths = $uploadedFiles['tmp_name'] ?? [];

        if (!is_array($temporaryPaths)) {
            $temporaryPaths = [];
        }

        foreach ($temporaryPaths as $index => $temporaryPath) {
            $originalName = (string)($uploadedFiles['name'][$index] ?? 'upload.bin');
            $uploadError = (int)($uploadedFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            $uploadSize = is_string($temporaryPath) && is_file($temporaryPath)
                ? (int)filesize($temporaryPath)
                : 0;
            $uploadMeta = [
                'file_size' => $uploadSize,
                'file_size_text' => \catalog_bytes($uploadSize),
            ];

            if ($uploadError !== UPLOAD_ERR_OK) {
                $failed++;
                $message = 'Upload error ' . $uploadError;
                $messages[] = UploadResult::create('failed', $originalName, $message, $uploadMeta);
                $this->emitFailureProgress($progress, $originalName, $message);
                continue;
            }

            try {
                $result = $this->importer->import(
                    $this->db,
                    $this->config,
                    $gameId,
                    (string)$temporaryPath,
                    $originalName,
                    $userId,
                    $strictProfile,
                    $progress
                );

                $metadata = is_array($result[4] ?? null) ? $result[4] : $uploadMeta;
                $aliasAlreadyExists = ($result[0] ?? '') === 'alias'
                    && function_exists('catalog_package_alias_last_add_was_existing')
                    && \catalog_package_alias_last_add_was_existing();

                if (($result[0] ?? '') === 'duplicate' || $aliasAlreadyExists) {
                    $duplicates++;
                    $message = $aliasAlreadyExists
                        ? 'Package alias already exists for existing file identity'
                        : (string)($result[2] ?? 'Duplicate in selected game');
                    $messages[] = UploadResult::create(
                        'duplicate',
                        $originalName,
                        $message,
                        $metadata
                    );
                    continue;
                }

                $imported++;
                $messages[] = UploadResult::create(
                    'imported',
                    $originalName,
                    (string)($result[2] ?? 'Imported'),
                    $metadata
                );
            } catch (Throwable $exception) {
                $failed++;
                $message = UploadErrorFormatter::concise($exception);
                $this->logFailure($originalName, $exception);
                $this->importer->preserveFailedUpload(
                    $this->config,
                    (string)$temporaryPath,
                    $originalName,
                    (string)$game['slug'],
                    $exception->getMessage()
                );
                $messages[] = UploadResult::create(
                    'failed',
                    $originalName,
                    $message,
                    $uploadMeta
                );
                $this->emitFailureProgress($progress, $originalName, $message);
            }
        }

        return [
            'ok' => $imported,
            'duplicate' => $duplicates,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function resultText(array $entry): string
    {
        return UploadResult::text($entry);
    }

    private function emitFailureProgress(
        ?callable $progress,
        string $fileName,
        string $message
    ): void {
        if ($progress === null) {
            return;
        }

        $progress([
            'stage' => 'failed',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'message' => $fileName . ': failed - ' . $message,
        ]);
    }

    private function logFailure(string $filename, Throwable $exception): void
    {
        if ($this->failureLogger !== null) {
            $this->failureLogger->log($filename, $exception);
            return;
        }

        // Compatibility fallback for callers not yet composed through a factory.
        $details = $filename
            . ': '
            . get_class($exception)
            . ': '
            . $exception->getMessage()
            . "\n"
            . $exception->getTraceAsString();
        error_log('[UnrealDB upload] ' . $details);

        if (!function_exists('fed_log')) {
            return;
        }

        try {
            \fed_log($this->db, null, null, 'ERROR', 'UPLOAD_SCAN_FAIL', $details);
        } catch (Throwable) {
            // A failure in optional audit logging must not break the upload flow.
        }
    }
}
