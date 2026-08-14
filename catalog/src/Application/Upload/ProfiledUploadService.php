<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Orchestrates a profile-targeted package upload batch.
 * Why: HTTP/session concerns belong in controllers while database, parser and filesystem details remain behind application ports.
 * Role: Application use case for profile-targeted uploads.
 * Audit: Keep this class free of PDO, SQL, runtime config and Infrastructure dependencies.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload;

use Throwable;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Application\Upload\Contract\FailedUploadPreserver;
use UnrealDb\Catalog\Application\Upload\Contract\ProfiledUploadGameCatalog;
use UnrealDb\Catalog\Application\Upload\Contract\UploadFailureLogger;

final class ProfiledUploadService
{
    public function __construct(
        private readonly ProfiledUploadGameCatalog $games,
        private readonly CatalogPackageImporter $importer,
        private readonly ?UploadFailureLogger $failureLogger = null,
        private readonly ?FailedUploadPreserver $failedUploadPreserver = null
    ) {
    }

    /**
     * @param array<string,mixed> $uploadedFiles
     * @return array{ok:int,duplicate:int,failed:int,messages:list<array<string,mixed>>}
     */
    public function handle(
        int $gameId,
        bool $strictProfile,
        array $uploadedFiles,
        ?int $userId,
        ?callable $progress
    ): array {
        $gameSlug = $this->games->slug($gameId);
        if ($gameSlug === null || $gameSlug === '') {
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
                'file_size_text' => self::bytes($uploadSize),
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
                    $gameId,
                    (string)$temporaryPath,
                    $originalName,
                    $userId,
                    $strictProfile,
                    $progress
                );

                $metadata = is_array($result[4] ?? null) ? $result[4] : $uploadMeta;
                $aliasAlreadyExists = ($result[0] ?? '') === 'alias'
                    && !empty($metadata['alias_already_exists']);
                unset($metadata['alias_already_exists']);

                if (($result[0] ?? '') === 'duplicate' || $aliasAlreadyExists) {
                    $duplicates++;
                    $message = $aliasAlreadyExists
                        ? 'Package alias already exists for existing file identity'
                        : (string)($result[2] ?? 'Duplicate in selected game');
                    $messages[] = UploadResult::create('duplicate', $originalName, $message, $metadata);
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
                if ($this->failedUploadPreserver !== null) {
                    $this->failedUploadPreserver->preserve(
                        (string)$temporaryPath,
                        $originalName,
                        $gameSlug,
                        $exception->getMessage(),
                        $userId
                    );
                } else {
                    // Compatibility for older composition roots. New wiring supplies
                    // the dedicated retention port so Infrastructure never needs to
                    // rediscover request identity from session state.
                    $this->importer->preserveFailedUpload(
                        (string)$temporaryPath,
                        $originalName,
                        $gameSlug,
                        $exception->getMessage()
                    );
                }
                $messages[] = UploadResult::create('failed', $originalName, $message, $uploadMeta);
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

    /** @param array<string,mixed> $entry */
    public static function resultText(array $entry): string
    {
        return UploadResult::text($entry);
    }

    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit > 0 ? number_format($value, 2) : (string)$bytes) . ' ' . $units[$unit];
    }

    private function emitFailureProgress(?callable $progress, string $fileName, string $message): void
    {
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
        error_log('[UnrealDB upload] ' . $filename . ': ' . get_class($exception) . ': '
            . $exception->getMessage() . "\n" . $exception->getTraceAsString());
    }
}
