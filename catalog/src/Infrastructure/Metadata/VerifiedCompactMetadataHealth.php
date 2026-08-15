<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Verifies the authoritative format-2 container and keeps ue_files publication
 * state aligned with physical reality.
 *
 * A ue_file_metadata registration alone is not proof that the container still
 * exists or is readable. Callers that decide whether recovery is needed should
 * use this boundary rather than checking format_version directly.
 */
final class VerifiedCompactMetadataHealth
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function verify(PDO $db, array $config, int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('Compact metadata verification requires a positive file ID.');
        }
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata verification.');
        }

        try {
            $result = (new BlockedCompressedMetadataReader($db, $storageRoot))->verify($fileId);
            if (empty($result['verified'])
                || (int)($result['format_version'] ?? 0) !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
                throw new RuntimeException(
                    'File #' . $fileId . ' did not verify as compact metadata format version '
                    . BlockedCompressedMetadataContainer::FORMAT_VERSION . '.'
                );
            }
            VerifiedMetadataPublicationState::ready($db, $fileId);
            return $result;
        } catch (Throwable $error) {
            VerifiedMetadataPublicationState::failed($db, $fileId, self::errorText($error));
            throw $error;
        }
    }

    /** @param array<string,mixed> $config */
    public static function healthy(PDO $db, array $config, int $fileId): bool
    {
        try {
            self::verify($db, $config, $fileId);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        if ($message === '') {
            $message = get_class($error);
        }
        return mb_substr($message, 0, 60000, 'UTF-8');
    }
}
