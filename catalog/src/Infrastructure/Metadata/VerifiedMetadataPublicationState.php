<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use Throwable;

/** Records the explicit publication lifecycle without making deployment order brittle. */
final class VerifiedMetadataPublicationState
{
    private static ?bool $available = null;

    public static function pending(PDO $db, int $fileId): void
    {
        self::write($db, $fileId, 'pending', null);
    }

    public static function ready(PDO $db, int $fileId): void
    {
        self::write($db, $fileId, 'ready', null);
    }

    public static function failed(PDO $db, int $fileId, string $message): void
    {
        self::write($db, $fileId, 'failed', trim($message));
    }

    private static function write(PDO $db, int $fileId, string $status, ?string $error): void
    {
        if ($fileId < 1 || !self::available($db)) {
            return;
        }
        try {
            $statement = $db->prepare(
                'UPDATE ue_files SET metadata_status=?,metadata_error=?,metadata_updated_at=UTC_TIMESTAMP() WHERE id=?'
            );
            $statement->execute([$status, $error !== '' ? $error : null, $fileId]);
        } catch (Throwable $writeError) {
            error_log(
                '[UnrealDB compact metadata] file_id=' . $fileId
                . ' could not update publication state: ' . $writeError->getMessage()
            );
        }
    }

    private static function available(PDO $db): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        try {
            $statement = $db->query(
                'SELECT 1 FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" '
                . 'AND COLUMN_NAME="metadata_status" LIMIT 1'
            );
            return self::$available = $statement->fetchColumn() !== false;
        } catch (Throwable) {
            return self::$available = false;
        }
    }
}
