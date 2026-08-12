<?php
/**
 * Stores administrator-selected background-job event/diagnostic logging policy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;

final class CatalogJobLoggingSettingsStore
{
    private const TABLE = 'ue_job_logging_settings';
    private const CACHE_SECONDS = 5.0;

    /** @var array<string,bool> */
    private array $values = [];
    private float $loadedAt = 0.0;
    private ?bool $available = null;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,array{label:string,description:string,default:bool}> */
    public static function definitions(): array
    {
        return [
            'event_errors' => [
                'label' => 'Errors requiring attention',
                'description' => 'Failed, rejected, unreadable/unverified, encrypted or otherwise actionable job events.',
                'default' => true,
            ],
            'event_progress' => [
                'label' => 'Progress events',
                'description' => 'Routine running/checkpoint messages. Job progress on Background Jobs still works when this is disabled.',
                'default' => false,
            ],
            'event_success' => [
                'label' => 'Successful/completed events',
                'description' => 'Verified, imported, alias and completed event-stream entries.',
                'default' => false,
            ],
            'event_duplicate' => [
                'label' => 'Duplicate events',
                'description' => 'Expected duplicate/already-present results.',
                'default' => false,
            ],
            'event_skipped' => [
                'label' => 'Skipped events',
                'description' => 'Expected profile/filter skips that normally require no operator action.',
                'default' => false,
            ],
            'event_cancelled' => [
                'label' => 'Cancelled events',
                'description' => 'Jobs/files intentionally cancelled by an administrator.',
                'default' => false,
            ],
            'worker_diagnostics' => [
                'label' => 'Worker lifecycle diagnostics',
                'description' => 'Claim/start/heartbeat/completed lines written to the PHP error log. Disable for normal operation.',
                'default' => false,
            ],
        ];
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        try {
            $statement = $this->db->query(
                'SELECT COUNT(*) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="' . self::TABLE . '"'
            );
            return $this->available = (int)$statement->fetchColumn() === 1;
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    public function enabled(string $key, ?bool $fallback = null): bool
    {
        $definitions = self::definitions();
        $default = $fallback ?? (bool)($definitions[$key]['default'] ?? false);
        if (!$this->isAvailable()) {
            return $default;
        }
        if ($this->loadedAt <= 0.0 || microtime(true) - $this->loadedAt >= self::CACHE_SECONDS) {
            $this->reload();
        }
        return $this->values[$key] ?? $default;
    }

    /** @return array<string,bool> */
    public function all(): array
    {
        $values = [];
        foreach (self::definitions() as $key => $definition) {
            $values[$key] = $this->enabled($key, (bool)$definition['default']);
        }
        return $values;
    }

    /** @param array<string,bool|int|string> $values */
    public function save(array $values, ?int $updatedBy): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'The job logging migration has not been applied. Run catalog/bin/migrate.php migrate.'
            );
        }
        $definitions = self::definitions();
        $statement = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (setting_key,enabled,updated_by) VALUES (?,?,?) '
            . 'ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
        );
        $this->db->beginTransaction();
        try {
            foreach ($definitions as $key => $definition) {
                $enabled = !empty($values[$key]);
                $statement->execute([$key, $enabled ? 1 : 0, $updatedBy !== null && $updatedBy > 0 ? $updatedBy : null]);
                $this->values[$key] = $enabled;
            }
            $this->db->commit();
            $this->loadedAt = microtime(true);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function shouldWriteEvent(string $status): bool
    {
        $status = strtolower(trim($status));
        if (in_array($status, [
            'failed', 'failure', 'error', 'dead_letter', 'dead-letter', 'rejected', 'unverified',
            'not_extracted', 'not-extracted', 'encrypted', 'broken', 'corrupt', 'invalid',
        ], true)) {
            return $this->enabled('event_errors', true);
        }
        if (in_array($status, ['duplicate', 'deduplicated', 'already_present', 'already-present'], true)) {
            return $this->enabled('event_duplicate', false);
        }
        if (in_array($status, ['skipped', 'ignored'], true)) {
            return $this->enabled('event_skipped', false);
        }
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return $this->enabled('event_cancelled', false);
        }
        if (in_array($status, ['completed', 'complete', 'verified', 'imported', 'alias', 'success', 'ready'], true)) {
            return $this->enabled('event_success', false);
        }
        return $this->enabled('event_progress', false);
    }

    private function reload(): void
    {
        $this->values = [];
        try {
            $statement = $this->db->query('SELECT setting_key,enabled FROM ' . self::TABLE);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = trim((string)($row['setting_key'] ?? ''));
                if ($key !== '') {
                    $this->values[$key] = (bool)($row['enabled'] ?? false);
                }
            }
            $this->available = true;
        } catch (Throwable) {
            $this->available = false;
            $this->values = [];
        }
        $this->loadedAt = microtime(true);
    }
}
