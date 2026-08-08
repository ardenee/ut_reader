<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Normalizes Game Manager lifecycle progress callbacks.
 * Why: Reset/delete collaborators must preserve the historical stage/done/total/percent/message contract without depending on controller-local gm_emit().
 * Role: Small infrastructure utility shared by game lifecycle collaborators.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

final class CatalogGameLifecycleProgress
{
    /** @param null|callable(array<string,mixed>):void $progress */
    public static function emit(
        ?callable $progress,
        string $stage,
        int $done,
        int $total,
        int $percent,
        string $message
    ): void {
        if ($progress === null) {
            return;
        }

        $progress([
            'stage' => $stage,
            'done' => max(0, $done),
            'total' => max(1, $total),
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
