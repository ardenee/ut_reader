<?php
/**
 * Administrator-configurable program settings with tracked config fallbacks.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Settings;

use PDO;
use Throwable;

final class CatalogProgramSettingsStore
{
    private const TABLE = 'ue_program_settings';
    private const NORMAL_UPLOAD_KEY = 'normal_upload_limit_bytes';
    private const CONTAINER_UPLOAD_KEY = 'container_upload_limit_bytes';
    private const MIN_UPLOAD_BYTES = 16 * 1024 * 1024;
    private const MAX_UPLOAD_BYTES = 1024 * 1024 * 1024 * 1024;

    private ?bool $available = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        try {
            $statement = $this->db->query(
                'SELECT 1 FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="' . self::TABLE . '" LIMIT 1'
            );
            return $this->available = (bool)$statement->fetchColumn();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    /** @return array{normal_upload_limit_bytes:int,container_upload_limit_bytes:int} */
    public function uploadLimits(): array
    {
        $normalFallback = $this->bounded(
            (int)($this->config['max_upload_bytes'] ?? (256 * 1024 * 1024))
        );
        $containerFallback = $this->bounded(
            (int)($this->config['max_container_upload_bytes'] ?? (64 * 1024 * 1024 * 1024))
        );
        $containerFallback = max($normalFallback, $containerFallback);

        if (!$this->isAvailable()) {
            return [
                'normal_upload_limit_bytes' => $normalFallback,
                'container_upload_limit_bytes' => $containerFallback,
            ];
        }

        $values = [];
        try {
            $statement = $this->db->prepare(
                'SELECT setting_key,setting_value FROM ' . self::TABLE . ' WHERE setting_key IN (?,?)'
            );
            $statement->execute([self::NORMAL_UPLOAD_KEY, self::CONTAINER_UPLOAD_KEY]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = trim((string)($row['setting_key'] ?? ''));
                $raw = trim((string)($row['setting_value'] ?? ''));
                if ($key !== '' && preg_match('/^[0-9]+$/', $raw) === 1) {
                    $values[$key] = $this->bounded((int)$raw);
                }
            }
        } catch (Throwable) {
            return [
                'normal_upload_limit_bytes' => $normalFallback,
                'container_upload_limit_bytes' => $containerFallback,
            ];
        }

        $normal = $values[self::NORMAL_UPLOAD_KEY] ?? $normalFallback;
        $container = max($normal, $values[self::CONTAINER_UPLOAD_KEY] ?? $containerFallback);
        return [
            'normal_upload_limit_bytes' => $normal,
            'container_upload_limit_bytes' => $container,
        ];
    }

    /** @return array{normal_upload_limit_bytes:int,container_upload_limit_bytes:int} */
    public function saveUploadLimits(int $normalBytes, int $containerBytes, ?int $updatedBy): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'The program-settings migration has not been applied. Run catalog/bin/migrate.php migrate.'
            );
        }
        $normalBytes = $this->bounded($normalBytes);
        $containerBytes = $this->bounded($containerBytes);
        if ($containerBytes < $normalBytes) {
            throw new \InvalidArgumentException('The PAK/container limit cannot be smaller than the normal package limit.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (setting_key,setting_value,updated_by) VALUES (?,?,?) '
            . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
        );
        $userId = $updatedBy !== null && $updatedBy > 0 ? $updatedBy : null;
        $this->db->beginTransaction();
        try {
            $statement->execute([self::NORMAL_UPLOAD_KEY, (string)$normalBytes, $userId]);
            $statement->execute([self::CONTAINER_UPLOAD_KEY, (string)$containerBytes, $userId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return [
            'normal_upload_limit_bytes' => $normalBytes,
            'container_upload_limit_bytes' => $containerBytes,
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function applyUploadLimits(array $config): array
    {
        $limits = $this->uploadLimits();
        $config['max_upload_bytes'] = $limits['normal_upload_limit_bytes'];
        $config['max_container_upload_bytes'] = $limits['container_upload_limit_bytes'];
        return $config;
    }

    private function bounded(int $bytes): int
    {
        return max(self::MIN_UPLOAD_BYTES, min(self::MAX_UPLOAD_BYTES, $bytes));
    }
}
