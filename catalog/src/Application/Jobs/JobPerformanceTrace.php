<?php
/**
 * Low-overhead, in-process performance telemetry for one durable job execution.
 *
 * The trace is deliberately passive: it performs no persistence or scheduling
 * work. JobExecutionContext attaches snapshots to the progress payload that is
 * already written by the durable queue.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use Closure;

final class JobPerformanceTrace
{
    private const MAX_STAGES = 64;

    private readonly Closure $clock;
    private readonly Closure $memoryUsage;
    private readonly Closure $peakMemoryUsage;
    private readonly int $startedAtNs;
    private readonly int $startedMemoryBytes;
    private ?string $stage = null;
    private int $stageStartedAtNs;

    /** @var array<string,int> */
    private array $stageNanoseconds = [];

    private int $observations = 0;

    public function __construct(
        ?Closure $clock = null,
        ?Closure $memoryUsage = null,
        ?Closure $peakMemoryUsage = null
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
        $this->memoryUsage = $memoryUsage ?? static fn(): int => memory_get_usage(true);
        $this->peakMemoryUsage = $peakMemoryUsage ?? static fn(): int => memory_get_peak_usage(true);

        $now = $this->now();
        $this->startedAtNs = $now;
        $this->stageStartedAtNs = $now;
        $this->startedMemoryBytes = max(0, (int)($this->memoryUsage)());
    }

    public function observe(string $stage): void
    {
        $stage = $this->stageBucket($this->normalizeStage($stage));
        $now = $this->now();

        if ($this->stage === null) {
            $this->stage = $stage;
            $this->stageStartedAtNs = $now;
        } elseif ($stage !== $this->stage) {
            $this->stageNanoseconds[$this->stage] = ($this->stageNanoseconds[$this->stage] ?? 0)
                + max(0, $now - $this->stageStartedAtNs);
            $this->stage = $stage;
            $this->stageStartedAtNs = $now;
        }

        $this->observations++;
    }

    /**
     * @return array{
     *   runtime_ms:float,
     *   current_stage:string,
     *   current_stage_ms:float,
     *   stage_ms:array<string,float>,
     *   memory_bytes:int,
     *   peak_memory_bytes:int,
     *   memory_delta_bytes:int,
     *   observations:int
     * }
     */
    public function snapshot(): array
    {
        $now = $this->now();
        $stageNanoseconds = $this->stageNanoseconds;
        $currentStage = $this->stage ?? 'unreported';
        $currentStageNanoseconds = 0;

        if ($this->stage !== null) {
            $currentStageNanoseconds = max(0, $now - $this->stageStartedAtNs);
            $stageNanoseconds[$this->stage] = ($stageNanoseconds[$this->stage] ?? 0)
                + $currentStageNanoseconds;
        }

        $stageMilliseconds = [];
        foreach ($stageNanoseconds as $stage => $nanoseconds) {
            $stageMilliseconds[$stage] = self::milliseconds($nanoseconds);
        }

        $memoryBytes = max(0, (int)($this->memoryUsage)());
        $peakMemoryBytes = max($memoryBytes, max(0, (int)($this->peakMemoryUsage)()));

        return [
            'runtime_ms' => self::milliseconds(max(0, $now - $this->startedAtNs)),
            'current_stage' => $currentStage,
            'current_stage_ms' => self::milliseconds($currentStageNanoseconds),
            'stage_ms' => $stageMilliseconds,
            'memory_bytes' => $memoryBytes,
            'peak_memory_bytes' => $peakMemoryBytes,
            'memory_delta_bytes' => $memoryBytes - $this->startedMemoryBytes,
            'observations' => $this->observations,
        ];
    }

    private function now(): int
    {
        return max(0, (int)($this->clock)());
    }

    private function stageBucket(string $stage): string
    {
        if ($stage === $this->stage || isset($this->stageNanoseconds[$stage])) {
            return $stage;
        }

        $knownStages = count($this->stageNanoseconds);
        if ($this->stage !== null && !isset($this->stageNanoseconds[$this->stage])) {
            $knownStages++;
        }
        return $knownStages >= self::MAX_STAGES ? 'other' : $stage;
    }

    private function normalizeStage(string $stage): string
    {
        $stage = strtolower(trim($stage));
        if ($stage === '') {
            return 'running';
        }

        $stage = (string)preg_replace('/[^a-z0-9._:-]+/', '_', $stage);
        $stage = trim($stage, '_');
        return substr($stage, 0, 80) ?: 'running';
    }

    private static function milliseconds(int $nanoseconds): float
    {
        return round($nanoseconds / 1_000_000, 3);
    }
}
