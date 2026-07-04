<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

/**
 * Removes stale upload-progress files outside interactive PHP requests.
 */
final class UploadProgressPruner
{
    public function prune(int $maxAgeSeconds): int
    {
        $cutoff = time() - max(60, $maxAgeSeconds);
        $pattern = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'unrealdb-upload-progress-*.json*';
        $removed = 0;

        foreach (glob($pattern) ?: [] as $path) {
            $modifiedAt = @filemtime($path);
            if ($modifiedAt !== false && $modifiedAt < $cutoff && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
