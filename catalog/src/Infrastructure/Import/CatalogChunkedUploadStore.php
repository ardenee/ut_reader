<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogChunkedUploadStore
{
    private string $root;
    private int $chunkBytes;
    private int $maxBytes;
    private int $staleSeconds;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for chunked uploads.');
        }
        $chunkConfig = is_array($config['chunk_upload'] ?? null) ? $config['chunk_upload'] : [];
        $this->root = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'chunked-uploads';
        $this->chunkBytes = max(1024 * 1024, min((int)($chunkConfig['chunk_bytes'] ?? (16 * 1024 * 1024)), 64 * 1024 * 1024));
        $this->maxBytes = max(
            (int)($config['max_upload_bytes'] ?? 0),
            (int)($config['max_container_upload_bytes'] ?? (64 * 1024 * 1024 * 1024))
        );
        $staleHours = max(1, min((int)($chunkConfig['stale_hours'] ?? 168), 24 * 90));
        $this->staleSeconds = $staleHours * 3600;
    }

    public function chunkBytes(): int
    {
        return $this->chunkBytes;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** @return array<string,mixed> */
    public function initialize(
        int $userId,
        string $clientKey,
        string $originalName,
        string $relativePath,
        int $fileSize,
        int $gameId,
        bool $strictProfile
    ): array {
        if ($userId < 1) {
            throw new \RuntimeException('Chunked uploads require an authenticated administrator.');
        }
        if ($gameId < 1) {
            throw new \InvalidArgumentException('Choose a valid target game.');
        }
        $clientKey = trim($clientKey);
        if ($clientKey === '' || strlen($clientKey) > 2048) {
            throw new \InvalidArgumentException('The chunked upload key is invalid.');
        }
        $originalName = $this->cleanOriginalName($originalName);
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            throw new \InvalidArgumentException('Chunked browser uploads are available for .pak containers.');
        }
        if ($fileSize < 1 || $fileSize > $this->maxBytes) {
            throw new \RuntimeException('PAK size exceeds the configured container upload limit.');
        }
        $relativePath = $this->cleanRelativePath($relativePath !== '' ? $relativePath : $originalName);
        $uploadId = hash('sha256', $userId . "\0" . $clientKey);
        $directory = $this->directory($uploadId);
        $this->ensureDirectory($directory);
        $lock = $this->lock($directory);
        try {
            $manifest = $this->readManifest($directory, false);
            $expected = [
                'upload_id' => $uploadId,
                'user_id' => $userId,
                'client_key_hash' => hash('sha256', $clientKey),
                'original_name' => $originalName,
                'relative_path' => $relativePath,
                'file_size' => $fileSize,
                'game_id' => $gameId,
                'strict_profile' => $strictProfile,
                'chunk_bytes' => $this->chunkBytes,
                'total_chunks' => (int)ceil($fileSize / $this->chunkBytes),
            ];
            if ($manifest !== null) {
                foreach ($expected as $key => $value) {
                    if (($manifest[$key] ?? null) !== $value) {
                        throw new \RuntimeException('A resumable upload with the same browser key has different metadata. Cancel it before retrying.');
                    }
                }
                if (!is_file($this->dataPath($directory))) {
                    $manifest['received'] = [];
                    $manifest['status'] = 'uploading';
                }
            } else {
                $manifest = $expected + [
                    'status' => 'uploading',
                    'received' => [],
                    'created_at' => gmdate(DATE_ATOM),
                ];
            }
            $manifest['updated_at'] = gmdate(DATE_ATOM);
            $this->writeManifest($directory, $manifest);
            return $this->publicState($manifest);
        } finally {
            $this->unlock($lock);
        }
    }

    /** @return array<string,mixed> */
    public function writeChunk(int $userId, string $uploadId, int $chunkIndex, string $temporaryPath, int $uploadError): array
    {
        $directory = $this->existingDirectory($uploadId);
        $lock = $this->lock($directory);
        try {
            $manifest = $this->ownedManifest($directory, $userId);
            if ((string)($manifest['status'] ?? '') === 'complete') {
                return $this->publicState($manifest);
            }
            $totalChunks = (int)$manifest['total_chunks'];
            if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
                throw new \InvalidArgumentException('Chunk index is outside the upload range.');
            }
            if ($uploadError !== UPLOAD_ERR_OK || $temporaryPath === '' || !is_file($temporaryPath)) {
                throw new \RuntimeException('Chunk upload failed with PHP upload error ' . $uploadError . '.');
            }
            $offset = $chunkIndex * (int)$manifest['chunk_bytes'];
            $expectedSize = min((int)$manifest['chunk_bytes'], (int)$manifest['file_size'] - $offset);
            $actualSize = filesize($temporaryPath);
            if ($actualSize === false || (int)$actualSize !== $expectedSize) {
                throw new \RuntimeException('Chunk size mismatch: expected ' . $expectedSize . ' bytes.');
            }
            $hash = hash_file('sha256', $temporaryPath);
            if (!is_string($hash)) {
                throw new \RuntimeException('Could not hash the uploaded chunk.');
            }
            $key = 'c' . $chunkIndex;
            $received = is_array($manifest['received'] ?? null) ? $manifest['received'] : [];
            if (isset($received[$key])
                && (int)($received[$key]['bytes'] ?? -1) === $expectedSize
                && hash_equals((string)($received[$key]['sha256'] ?? ''), $hash)) {
                return $this->publicState($manifest);
            }

            $input = fopen($temporaryPath, 'rb');
            $output = fopen($this->dataPath($directory), 'c+b');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new \RuntimeException('Could not open durable chunk storage.');
            }
            try {
                if (!flock($output, LOCK_EX) || fseek($output, $offset) !== 0) {
                    throw new \RuntimeException('Could not position durable chunk storage.');
                }
                $remaining = $expectedSize;
                while ($remaining > 0) {
                    $buffer = fread($input, min(1024 * 1024, $remaining));
                    if (!is_string($buffer) || $buffer === '') {
                        throw new \RuntimeException('Uploaded chunk ended unexpectedly.');
                    }
                    $written = 0;
                    $length = strlen($buffer);
                    while ($written < $length) {
                        $count = fwrite($output, substr($buffer, $written));
                        if ($count === false || $count === 0) {
                            throw new \RuntimeException('Could not write durable chunk data.');
                        }
                        $written += $count;
                    }
                    $remaining -= $length;
                }
                fflush($output);
            } finally {
                flock($output, LOCK_UN);
                fclose($output);
                fclose($input);
            }

            $received[$key] = ['bytes' => $expectedSize, 'sha256' => $hash];
            $manifest['received'] = $received;
            $manifest['status'] = 'uploading';
            $manifest['updated_at'] = gmdate(DATE_ATOM);
            $this->writeManifest($directory, $manifest);
            return $this->publicState($manifest);
        } finally {
            $this->unlock($lock);
        }
    }

    /** @return array<string,mixed> */
    public function complete(int $userId, string $uploadId): array
    {
        $directory = $this->existingDirectory($uploadId);
        $lock = $this->lock($directory);
        try {
            $manifest = $this->ownedManifest($directory, $userId);
            $received = is_array($manifest['received'] ?? null) ? $manifest['received'] : [];
            $totalChunks = (int)$manifest['total_chunks'];
            for ($index = 0; $index < $totalChunks; $index++) {
                if (!isset($received['c' . $index])) {
                    throw new \RuntimeException('Chunked upload is incomplete at chunk ' . ($index + 1) . ' of ' . $totalChunks . '.');
                }
            }
            $path = $this->dataPath($directory);
            $size = is_file($path) ? filesize($path) : false;
            if ($size === false || (int)$size !== (int)$manifest['file_size']) {
                throw new \RuntimeException('Completed chunked upload size does not match the selected PAK.');
            }
            $manifest['status'] = 'complete';
            $manifest['completed_at'] = gmdate(DATE_ATOM);
            $manifest['updated_at'] = gmdate(DATE_ATOM);
            $this->writeManifest($directory, $manifest);
            return $this->publicState($manifest);
        } finally {
            $this->unlock($lock);
        }
    }

    /** @return array{path:string,manifest:array<string,mixed>} */
    public function resolveCompletedFile(string $uploadId, ?int $userId = null): array
    {
        $directory = $this->existingDirectory($uploadId);
        $lock = $this->lock($directory);
        try {
            $manifest = $this->readManifest($directory, true);
            if ($userId !== null && $userId > 0 && (int)($manifest['user_id'] ?? 0) !== $userId) {
                throw new \RuntimeException('Chunked upload belongs to a different administrator.');
            }
            if ((string)($manifest['status'] ?? '') !== 'complete') {
                throw new \RuntimeException('Chunked PAK upload has not been completed.');
            }
            $path = realpath($this->dataPath($directory));
            $root = realpath($this->root);
            if ($path === false || $root === false || !is_file($path) || is_link($path)) {
                throw new \RuntimeException('Completed chunked PAK data is unavailable.');
            }
            $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
            if (!str_starts_with(str_replace('\\', '/', $path), $prefix)) {
                throw new \RuntimeException('Chunked PAK data escaped controlled storage.');
            }
            $size = filesize($path);
            if ($size === false || (int)$size !== (int)$manifest['file_size']) {
                throw new \RuntimeException('Completed chunked PAK size changed before processing.');
            }
            return ['path' => $path, 'manifest' => $manifest];
        } finally {
            $this->unlock($lock);
        }
    }

    public function cancel(int $userId, string $uploadId): void
    {
        $directory = $this->existingDirectory($uploadId);
        $lock = $this->lock($directory);
        try {
            $this->ownedManifest($directory, $userId);
            @unlink($this->dataPath($directory));
            @unlink($this->manifestPath($directory));
        } finally {
            $this->unlock($lock);
        }
        @unlink($directory . DIRECTORY_SEPARATOR . '.lock');
        @rmdir($directory);
        @rmdir(dirname($directory));
    }

    /** @return array{uploads:int,bytes:int} */
    public function prune(): array
    {
        $this->ensureDirectory($this->root);
        $threshold = time() - $this->staleSeconds;
        $uploads = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isDir()) {
                continue;
            }
            $manifestPath = $entry->getPathname() . DIRECTORY_SEPARATOR . 'manifest.json';
            if (!is_file($manifestPath) || (int)filemtime($manifestPath) >= $threshold) {
                @rmdir($entry->getPathname());
                continue;
            }
            $dataPath = $entry->getPathname() . DIRECTORY_SEPARATOR . 'payload.part';
            $size = is_file($dataPath) ? (int)(filesize($dataPath) ?: 0) : 0;
            $this->deleteTree($entry->getPathname());
            $uploads++;
            $bytes += $size;
        }
        return ['uploads' => $uploads, 'bytes' => $bytes];
    }

    /** @return array<string,mixed> */
    private function publicState(array $manifest): array
    {
        $received = is_array($manifest['received'] ?? null) ? $manifest['received'] : [];
        $receivedIndexes = [];
        $receivedBytes = 0;
        foreach ($received as $key => $entry) {
            if (preg_match('/^c([0-9]+)$/', (string)$key, $match) === 1) {
                $receivedIndexes[] = (int)$match[1];
                $receivedBytes += max(0, (int)($entry['bytes'] ?? 0));
            }
        }
        sort($receivedIndexes, SORT_NUMERIC);
        $fileSize = max(1, (int)($manifest['file_size'] ?? 1));
        return [
            'upload_id' => (string)$manifest['upload_id'],
            'status' => (string)($manifest['status'] ?? 'uploading'),
            'original_name' => (string)$manifest['original_name'],
            'relative_path' => (string)$manifest['relative_path'],
            'file_size' => (int)$manifest['file_size'],
            'game_id' => (int)$manifest['game_id'],
            'strict_profile' => (bool)$manifest['strict_profile'],
            'chunk_bytes' => (int)$manifest['chunk_bytes'],
            'total_chunks' => (int)$manifest['total_chunks'],
            'received_chunks' => $receivedIndexes,
            'received_bytes' => $receivedBytes,
            'percent' => (int)floor(($receivedBytes * 100) / $fileSize),
        ];
    }

    /** @return array<string,mixed> */
    private function ownedManifest(string $directory, int $userId): array
    {
        $manifest = $this->readManifest($directory, true);
        if ($userId < 1 || (int)($manifest['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Chunked upload belongs to a different administrator.');
        }
        return $manifest;
    }

    /** @return array<string,mixed>|null */
    private function readManifest(string $directory, bool $required): ?array
    {
        $path = $this->manifestPath($directory);
        if (!is_file($path)) {
            if ($required) {
                throw new \RuntimeException('Chunked upload manifest is missing.');
            }
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException('Could not read chunked upload manifest.');
        }
        try {
            $manifest = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Chunked upload manifest is corrupt.', 0, $error);
        }
        if (!is_array($manifest)) {
            throw new \RuntimeException('Chunked upload manifest is invalid.');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function writeManifest(string $directory, array $manifest): void
    {
        $path = $this->manifestPath($directory);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
                throw new \RuntimeException('Could not publish chunked upload manifest.');
            }
            @chmod($path, 0640);
        } finally {
            @unlink($temporary);
        }
    }

    /** @return resource */
    private function lock(string $directory)
    {
        $this->ensureDirectory($directory);
        $handle = fopen($directory . DIRECTORY_SEPARATOR . '.lock', 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Could not lock chunked upload state.');
        }
        return $handle;
    }

    /** @param resource $handle */
    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function existingDirectory(string $uploadId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Chunked upload identifier is invalid.');
        }
        $directory = $this->directory($uploadId);
        if (!is_dir($directory)) {
            throw new \RuntimeException('Chunked upload was not found.');
        }
        return $directory;
    }

    private function directory(string $uploadId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . substr($uploadId, 0, 2) . DIRECTORY_SEPARATOR . $uploadId;
    }

    private function manifestPath(string $directory): string
    {
        return $directory . DIRECTORY_SEPARATOR . 'manifest.json';
    }

    private function dataPath(string $directory): string
    {
        return $directory . DIRECTORY_SEPARATOR . 'payload.part';
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create chunked upload storage.');
        }
    }

    private function cleanOriginalName(string $name): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = rtrim(trim($name), ' .');
        return $name !== '' ? $name : 'archive.pak';
    }

    private function cleanRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', trim(str_replace(["\0", '\\'], ['', '/'], $path), '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts) ?: 'archive.pak';
    }

    private function deleteTree(string $directory): void
    {
        $root = realpath($this->root);
        $real = realpath($directory);
        if ($root === false || $real === false) {
            return;
        }
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $real) . '/', $prefix)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($real);
    }
}
