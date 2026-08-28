<?php
/**
 * Administrator-controlled limits for anonymous/public contribution uploads.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Settings;

use PDO;
use Throwable;

final class CatalogPublicUploadSettingsStore
{
    private const TABLE = 'ue_program_settings';

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @return array{
     *   enabled:bool,max_file_bytes:int,files_per_hour:int,bytes_per_hour:int,
     *   max_outstanding:int,min_free_bytes:int,reservation_seconds:int
     * }
     */
    public function settings(): array
    {
        $normalFallback = max(16 * 1024 * 1024, (int)($this->config['max_upload_bytes'] ?? (512 * 1024 * 1024)));
        $defaults = [
            'enabled' => true,
            'max_file_bytes' => $normalFallback,
            'files_per_hour' => 2000,
            'bytes_per_hour' => 50 * 1024 * 1024 * 1024,
            'max_outstanding' => 100,
            'min_free_bytes' => 20 * 1024 * 1024 * 1024,
            'reservation_seconds' => 24 * 3600,
        ];

        try {
            $statement = $this->db->prepare(
                'SELECT setting_key,setting_value FROM ' . self::TABLE . ' WHERE setting_key IN (?,?,?,?,?,?,?)'
            );
            $statement->execute([
                'public_upload_enabled',
                'public_upload_max_file_bytes',
                'public_upload_files_per_hour',
                'public_upload_bytes_per_hour',
                'public_upload_max_outstanding',
                'public_upload_min_free_bytes',
                'public_upload_reservation_seconds',
            ]);
            $values = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $values[(string)$row['setting_key']] = trim((string)$row['setting_value']);
            }
        } catch (Throwable) {
            return $defaults;
        }

        return [
            'enabled' => $this->bool($values['public_upload_enabled'] ?? null, $defaults['enabled']),
            'max_file_bytes' => $this->integer(
                $values['public_upload_max_file_bytes'] ?? null,
                $defaults['max_file_bytes'],
                16 * 1024 * 1024,
                1024 * 1024 * 1024 * 1024
            ),
            'files_per_hour' => $this->integer($values['public_upload_files_per_hour'] ?? null, $defaults['files_per_hour'], 1, 100000),
            'bytes_per_hour' => $this->integer(
                $values['public_upload_bytes_per_hour'] ?? null,
                $defaults['bytes_per_hour'],
                16 * 1024 * 1024,
                PHP_INT_MAX
            ),
            'max_outstanding' => $this->integer($values['public_upload_max_outstanding'] ?? null, $defaults['max_outstanding'], 1, 1000),
            'min_free_bytes' => $this->integer(
                $values['public_upload_min_free_bytes'] ?? null,
                $defaults['min_free_bytes'],
                1024 * 1024 * 1024,
                PHP_INT_MAX
            ),
            'reservation_seconds' => $this->integer(
                $values['public_upload_reservation_seconds'] ?? null,
                $defaults['reservation_seconds'],
                3600,
                7 * 86400
            ),
        ];
    }

    /**
     * @param array<string,int|bool> $values
     * @return array<string,int|bool>
     */
    public function save(array $values, ?int $updatedBy): array
    {
        $current = $this->settings();
        $normalized = [
            'public_upload_enabled' => !empty($values['enabled']) ? '1' : '0',
            'public_upload_max_file_bytes' => (string)$this->integer(
                (string)($values['max_file_bytes'] ?? ''),
                (int)$current['max_file_bytes'],
                16 * 1024 * 1024,
                1024 * 1024 * 1024 * 1024
            ),
            'public_upload_files_per_hour' => (string)$this->integer(
                (string)($values['files_per_hour'] ?? ''),
                (int)$current['files_per_hour'],
                1,
                100000
            ),
            'public_upload_bytes_per_hour' => (string)$this->integer(
                (string)($values['bytes_per_hour'] ?? ''),
                (int)$current['bytes_per_hour'],
                16 * 1024 * 1024,
                PHP_INT_MAX
            ),
            'public_upload_max_outstanding' => (string)$this->integer(
                (string)($values['max_outstanding'] ?? ''),
                (int)$current['max_outstanding'],
                1,
                1000
            ),
            'public_upload_min_free_bytes' => (string)$this->integer(
                (string)($values['min_free_bytes'] ?? ''),
                (int)$current['min_free_bytes'],
                1024 * 1024 * 1024,
                PHP_INT_MAX
            ),
            'public_upload_reservation_seconds' => (string)$this->integer(
                (string)($values['reservation_seconds'] ?? ''),
                (int)$current['reservation_seconds'],
                3600,
                7 * 86400
            ),
        ];

        $statement = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (setting_key,setting_value,updated_by) VALUES (?,?,?) '
            . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
        );
        $this->db->beginTransaction();
        try {
            foreach ($normalized as $key => $value) {
                $statement->execute([$key, $value, $updatedBy !== null && $updatedBy > 0 ? $updatedBy : null]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return $this->settings();
    }

    private function bool(?string $value, bool $fallback): bool
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function integer(?string $value, int $fallback, int $minimum, int $maximum): int
    {
        if ($value === null || preg_match('/^[0-9]+$/', trim($value)) !== 1) {
            return max($minimum, min($maximum, $fallback));
        }
        $number = (int)$value;
        return max($minimum, min($maximum, $number));
    }
}
