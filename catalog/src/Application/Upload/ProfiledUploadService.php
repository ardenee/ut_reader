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
     * @return array{ok:int,duplicate:int,failed:int,messages:list<array{status:string,file:string,message:string}>}
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
            if ($uploadError !== UPLOAD_ERR_OK) {
                $failed++;
                $message = 'Upload error ' . $uploadError;
                $messages[] = self::result('failed', $originalName, $message);
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

                if (($result[0] ?? '') === 'duplicate') {
                    $duplicates++;
                    $messages[] = self::result('duplicate', $originalName, (string)($result[2] ?? 'Duplicate in selected game'));
                    continue;
                }

                $imported++;
                $messages[] = self::result('imported', $originalName, (string)($result[2] ?? 'Imported'));
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
                $messages[] = self::result('failed', $originalName, $message);
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
     * @param array{status:string,file:string,message:string} $entry
     */
    public static function resultText(array $entry): string
    {
        return $entry['file'] . ': ' . $entry['status'] . ' - ' . $entry['message'];
    }

    private static function result(string $status, string $file, string $message): array
    {
        return ['status' => $status, 'file' => $file, 'message' => $message];
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
