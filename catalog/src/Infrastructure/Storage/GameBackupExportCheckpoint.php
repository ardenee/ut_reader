<?php
/**
 * Immutable export plan + append-only completion journal for restart-safe game backups.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

final class GameBackupExportCheckpoint
{
    public function __construct(
        private readonly GameBackupStore $store,
        private readonly string $backupKey
    ) {
    }

    /** @param array<string,mixed> $plan */
    public function writePlan(array $plan): void
    {
        $this->writeJson($this->planPath(), $plan);
    }

    /** @return array<string,mixed> */
    public function readPlan(): array
    {
        return $this->readJson($this->planPath());
    }

    public function planExists(): bool
    {
        return is_file($this->planPath());
    }

    /** @return list<array<string,mixed>> */
    public function journal(): array
    {
        return $this->store->readExportJournal($this->backupKey);
    }

    /** @param array<string,mixed> $entry */
    public function completeEntry(array $entry): void
    {
        $this->store->appendExportJournal($this->backupKey, $entry);
    }

    public function clear(): void
    {
        $this->store->clearExportJournal($this->backupKey);
        $plan = $this->planPath();
        if (is_file($plan) && !@unlink($plan)) {
            throw new \RuntimeException('Could not remove completed game-backup export plan.');
        }
    }

    private function planPath(): string
    {
        return $this->store->backupPath($this->backupKey) . DIRECTORY_SEPARATOR . 'export-plan.json';
    }

    /** @param array<string,mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not persist game-backup export plan.');
        }
        @chmod($path, 0640);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Game-backup export plan is invalid.');
        }
        return $decoded;
    }
}
