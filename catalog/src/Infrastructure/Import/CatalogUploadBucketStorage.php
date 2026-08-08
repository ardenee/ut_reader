<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Moves prepared package bytes into neutral Upload Bucket storage with verified cross-volume fallback copying.
 * Why: Filesystem placement is independent from hashing, duplicate policy and database indexing.
 * Role: Infrastructure storage collaborator used by Upload Bucket package operations.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use Throwable;

final class CatalogUploadBucketStorage
{
    public function __construct(private readonly CatalogUnverifiedPackageRuntime $runtime)
    {
    }

    /** @return array{queue_name:string,original_name:string,size:int,path:string} */
    public function store(string $sourcePath, string $originalName, string $reason): array
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Prepared Upload Bucket source is missing.');
        }

        $directory = $this->runtime->bucketDirectory(true);
        $cleanName = $this->runtime->cleanFilename($originalName);
        $queueName = $this->runtime->safeQueueName($cleanName);
        $destination = $this->runtime->uniqueDestination($directory, $queueName);

        if (!@rename($sourcePath, $destination)) {
            $this->copyAcrossVolumes($sourcePath, $destination);
            if (!@unlink($sourcePath) && is_file($sourcePath)) {
                @unlink($destination);
                throw new \RuntimeException('Could not remove the prepared source after copying it into the Upload Bucket.');
            }
        }

        @file_put_contents($destination . '.txt', $reason);
        return [
            'queue_name' => basename($destination),
            'original_name' => $cleanName,
            'size' => (int)(filesize($destination) ?: 0),
            'path' => $destination,
        ];
    }

    private function copyAcrossVolumes(string $sourcePath, string $destination): void
    {
        $input = fopen($sourcePath, 'rb');
        $output = fopen($destination, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($destination);
            throw new \RuntimeException('Could not create the Upload Bucket storage copy.');
        }

        $expected = (int)(filesize($sourcePath) ?: 0);
        $writtenTotal = 0;
        try {
            while (!feof($input)) {
                $buffer = fread($input, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new \RuntimeException('Could not read the prepared package during bucket storage.');
                }
                if ($buffer === '') {
                    if (feof($input)) {
                        break;
                    }
                    throw new \RuntimeException('Prepared package ended unexpectedly during bucket storage.');
                }
                $offset = 0;
                $length = strlen($buffer);
                while ($offset < $length) {
                    $count = fwrite($output, substr($buffer, $offset));
                    if ($count === false || $count === 0) {
                        throw new \RuntimeException('Could not write the Upload Bucket storage copy.');
                    }
                    $offset += $count;
                    $writtenTotal += $count;
                }
            }
            fflush($output);
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
        }

        $actual = is_file($destination) ? (int)(filesize($destination) ?: 0) : 0;
        if ($expected < 1 || $writtenTotal !== $expected || $actual !== $expected) {
            @unlink($destination);
            throw new \RuntimeException('Upload Bucket storage copy size verification failed.');
        }
    }
}
