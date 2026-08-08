<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Calculates MD5/SHA-1 package identity with bounded streaming reads and progress reporting.
 * Why: Hashing is reusable import infrastructure and must not be hidden inside a monolithic Upload Bucket processor.
 * Role: Infrastructure collaborator used by Upload Bucket staging and metadata repair.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogPackageIdentityHasher
{
    /** @param callable(array<string,mixed>):void|null $progress @return array{md5:string,sha1:string} */
    public function hash(string $path, int $size, ?callable $progress = null): array
    {
        $input = fopen($path, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Could not open the prepared package for hashing.');
        }
        $md5Context = hash_init('md5');
        $sha1Context = hash_init('sha1');
        $done = 0;
        $lastReport = microtime(true);
        try {
            while (!feof($input)) {
                $buffer = fread($input, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new \RuntimeException('Could not read the prepared package while hashing.');
                }
                if ($buffer === '') {
                    if (feof($input)) {
                        break;
                    }
                    throw new \RuntimeException('Package hashing stopped before end of file.');
                }
                hash_update($md5Context, $buffer);
                hash_update($sha1Context, $buffer);
                $done += strlen($buffer);
                $now = microtime(true);
                if (($now - $lastReport) >= 1.0 || $done >= $size) {
                    $fraction = $size > 0 ? min(1, $done / $size) : 1;
                    self::emit(
                        $progress,
                        'hash_identity',
                        45 + (int)floor($fraction * 10),
                        'Calculating MD5 and SHA-1: ' . $done . ' of ' . $size . ' bytes.',
                        ['bytes_done' => $done, 'bytes_total' => $size]
                    );
                    $lastReport = $now;
                }
            }
        } finally {
            fclose($input);
        }
        if ($done !== $size) {
            throw new \RuntimeException('Prepared package size changed while hashing.');
        }
        return ['md5' => hash_final($md5Context), 'sha1' => hash_final($sha1Context)];
    }

    /** @param callable(array<string,mixed>):void|null $progress @param array<string,mixed> $meta */
    private static function emit(?callable $progress, string $stage, int $percent, string $message, array $meta = []): void
    {
        if ($progress === null) {
            return;
        }
        $progress($meta + [
            'stage' => $stage,
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
