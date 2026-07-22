<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/Import/CatalogChunkedUploadStore.php';
require_once __DIR__ . '/../src/Infrastructure/Import/CatalogIncomingFileStore.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

function chunked_pak_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-chunk-test-' . bin2hex(random_bytes(6));
mkdir($root, 0775, true);
$config = [
    'storage_path' => $root,
    'max_upload_bytes' => 1024 * 1024,
    'max_container_upload_bytes' => 8 * 1024 * 1024,
    'chunk_upload' => ['chunk_bytes' => 1024 * 1024, 'stale_hours' => 1],
];

try {
    $store = new CatalogChunkedUploadStore($config);
    $size = (1024 * 1024) + 17;
    $state = $store->initialize(7, 'browser-key', 'LargeContainer.pak', 'Content/Paks/LargeContainer.pak', $size, 4, true);
    chunked_pak_expect((int)$state['total_chunks'] === 2, 'Chunked upload did not split the PAK into bounded requests.');

    $first = tempnam(sys_get_temp_dir(), 'chunk-a-');
    $second = tempnam(sys_get_temp_dir(), 'chunk-b-');
    file_put_contents($first, str_repeat('A', 1024 * 1024));
    file_put_contents($second, str_repeat('B', 17));
    $store->writeChunk(7, (string)$state['upload_id'], 0, $first, UPLOAD_ERR_OK);
    $store->writeChunk(7, (string)$state['upload_id'], 1, $second, UPLOAD_ERR_OK);
    @unlink($first);
    @unlink($second);

    $complete = $store->complete(7, (string)$state['upload_id']);
    chunked_pak_expect((string)$complete['status'] === 'complete', 'Chunked PAK was not marked complete.');
    $resolved = $store->resolveCompletedFile((string)$state['upload_id'], 7);
    chunked_pak_expect(is_file($resolved['path']) && filesize($resolved['path']) === $size, 'Completed PAK did not resolve from durable chunk storage.');

    $incoming = new CatalogIncomingFileStore($config);
    $pakPath = $root . DIRECTORY_SEPARATOR . 'local-test.pak';
    file_put_contents($pakPath, 'PAK');
    $encoded = rtrim(strtr(base64_encode($pakPath), '+/', '-_'), '=');
    chunked_pak_expect($incoming->resolve('local-pak:' . $encoded) === realpath($pakPath), 'Validated local PAK references do not resolve directly.');

    $store->cancel(7, (string)$state['upload_id']);
    chunked_pak_expect(!is_file($resolved['path']), 'Cancelled/consumed chunk storage was not removed.');

    $endpoint = file_get_contents(__DIR__ . '/../api/v1/profiled-upload-chunk.php');
    $javascript = file_get_contents(__DIR__ . '/../assets/profiled-upload-jobs.js');
    $queue = file_get_contents(__DIR__ . '/../src/Infrastructure/Import/CatalogProfiledUploadQueue.php');
    $sourceHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogSourceScanJobHandler.php');
    $sourceVariant = file_get_contents(__DIR__ . '/../lib/CatalogSourceScanNoContainers.php');
    foreach ([$endpoint, $javascript, $queue, $sourceHandler, $sourceVariant] as $content) {
        chunked_pak_expect(is_string($content), 'A required chunked PAK upload component is missing.');
    }
    chunked_pak_expect(str_contains($endpoint, "catalog_api_require_admin(false)"), 'Chunked upload endpoint is not admin-only.');
    chunked_pak_expect(str_contains($endpoint, "catalog_api_require_csrf('profiled_upload_chunk')"), 'Chunked upload endpoint lacks CSRF protection.');
    chunked_pak_expect(str_contains($javascript, "file.slice(start, end)"), 'Browser PAK upload does not send bounded chunks.');
    chunked_pak_expect(str_contains($javascript, 'received_chunks'), 'Browser PAK upload cannot resume stored chunks.');
    chunked_pak_expect(str_contains($queue, "'chunk-upload:' . \$uploadId"), 'Completed chunk uploads are not queued by durable reference.');
    chunked_pak_expect(str_contains($queue, "'local-pak:' . \$this->encodeLocalPath"), 'Local PAKs are copied instead of queued by validated path reference.');
    chunked_pak_expect(str_contains($sourceHandler, 'enqueueLocalPak('), 'Local source scans do not queue PAK containers separately.');
    chunked_pak_expect(str_contains($sourceVariant, "=== 'pak'"), 'Normal source package scanning does not exclude separately queued PAKs.');
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($root);
    }
}

echo "Chunked PAK upload contract tests passed.\n";
