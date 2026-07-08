<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;

/**
 * Application use case for a profile-targeted package upload batch.
 *
 * It owns request-independent orchestration: validation of uploaded files,
 * import outcome mapping, error presentation text, and durable failure logging.
 * HTTP/session/CSRF handling stays in the page controller; reader and storage
 * details stay behind CatalogPackageImporter.
 */
final class ProfiledUploadService
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly CatalogPackageImporter $importer
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
            $uploadSize = is_string($temporaryPath) && is_file($temporaryPath) ? (int)filesize($temporaryPath) : 0;
            $uploadMeta = [
                'file_size' => $uploadSize,
                'file_size_text' => \catalog_bytes($uploadSize),
            ];
            if ($uploadError !== UPLOAD_ERR_OK) {
                $failed++;
                $message = 'Upload error ' . $uploadError;
                $messages[] = self::result('failed', $originalName, $message, $uploadMeta);
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

                $meta = is_array($result[4] ?? null) ? $result[4] : $uploadMeta;
                $aliasAlreadyExists = ($result[0] ?? '') === 'alias'
                    && function_exists('catalog_package_alias_last_add_was_existing')
                    && \catalog_package_alias_last_add_was_existing();
                if (($result[0] ?? '') === 'duplicate' || $aliasAlreadyExists) {
                    $duplicates++;
                    $message = $aliasAlreadyExists
                        ? 'Package alias already exists for existing file identity'
                        : (string)($result[2] ?? 'Duplicate in selected game');
                    $messages[] = self::result('duplicate', $originalName, $message, $meta);
                    continue;
                }

                $imported++;
                $messages[] = self::result('imported', $originalName, (string)($result[2] ?? 'Imported'), $meta);
            } catch (Throwable $exception) {
                $failed++;
                $message = self::shortError($exception);
                $this->logFailure($originalName, $exception);
                $this->importer->preserveFailedUpload(
                    $this->config,
                    (string)$temporaryPath,
                    $originalName,
                    (string)$game['slug'],
                    $exception->getMessage()
                );
                $messages[] = self::result('failed', $originalName, $message, $uploadMeta);
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
        $text = $entry['file'] . ': ' . $entry['status'] . ' - ' . $entry['message'];
        if (!empty($entry['file_size_text'])) {
            $text .= ' | size: ' . (string)$entry['file_size_text'];
        }
        if (!empty($entry['package_guid'])) {
            $text .= ' | GUID: ' . (string)$entry['package_guid'];
        }
        if (!empty($entry['duplicate_original_name'])) {
            $text .= ' | copy of: ' . (string)$entry['duplicate_original_name'];
        }

        return $text;
    }

    private static function result(string $status, string $file, string $message, array $meta = []): array
    {
        unset($meta['duplicate_guid'], $meta['duplicate_file_size_text']);
        return ['status' => $status, 'file' => $file, 'message' => $message] + $meta;
    }

    private static function shortError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if (preg_match('/Bad package tag 0x[0-9A-Fa-f]+/', $message, $matches)) {
            return $matches[0];
        }

        $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
        $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
        $message = trim($message);

        return $message !== '' ? $message : 'Unknown error';
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
        $details = $filename . ': ' . get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString();
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
