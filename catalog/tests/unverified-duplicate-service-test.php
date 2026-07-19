<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedFileSystem;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedQueueInventory;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedRecordStore;
use UnrealDb\Catalog\Application\Unverified\UnverifiedDuplicateCleanupService;

function duplicate_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class TestQueueInventory implements UnverifiedQueueInventory
{
    public function all(): array
    {
        return [
            [
                'queue_game_id' => 0,
                'queue_name' => 'newer.utx',
                'queue_name_label' => 'Upload Bucket',
                'queue_key' => 'newer-key',
                'original_name' => 'newer.utx',
                'path' => '/queue/newer.utx',
                'reason_path' => '/queue/newer.utx.txt',
                'size' => 100,
                'modified_at' => 30,
            ],
            [
                'queue_game_id' => 2,
                'queue_name' => 'indexed.utx',
                'queue_name_label' => 'Game Two',
                'queue_key' => 'indexed-key',
                'original_name' => 'indexed.utx',
                'path' => '/queue/indexed.utx',
                'reason_path' => '/queue/indexed.utx.txt',
                'size' => 100,
                'modified_at' => 40,
            ],
            [
                'queue_game_id' => 1,
                'queue_name' => 'oldest.utx',
                'queue_name_label' => 'Game One',
                'queue_key' => 'oldest-key',
                'original_name' => 'oldest.utx',
                'path' => '/queue/oldest.utx',
                'reason_path' => '/queue/oldest.utx.txt',
                'size' => 100,
                'modified_at' => 10,
            ],
            [
                'queue_game_id' => 0,
                'queue_name' => 'unique.uax',
                'queue_name_label' => 'Upload Bucket',
                'queue_key' => 'unique-key',
                'original_name' => 'unique.uax',
                'path' => '/queue/unique.uax',
                'reason_path' => '/queue/unique.uax.txt',
                'size' => 200,
                'modified_at' => 20,
            ],
        ];
    }
}

final class TestRecordStore implements UnverifiedRecordStore
{
    /** @var list<string> */
    public array $deleted = [];

    public function indexedQueueKeys(): array
    {
        return ['indexed-key' => true];
    }

    public function deleteByQueue(int $queueGameId, string $queueName): void
    {
        $this->deleted[] = $queueGameId . ':' . $queueName;
    }
}

final class TestFileSystem implements UnverifiedFileSystem
{
    /** @var array<string, bool> */
    private array $existing = [
        '/queue/newer.utx' => true,
        '/queue/indexed.utx' => true,
        '/queue/oldest.utx' => true,
        '/queue/unique.uax' => true,
    ];

    /** @var list<string> */
    public array $deleted = [];

    public int $hashProgressCalls = 0;

    public function exists(string $path): bool
    {
        return !empty($this->existing[$path]);
    }

    public function size(string $path): int
    {
        return $path === '/queue/unique.uax' ? 200 : 100;
    }

    public function md5(string $path, ?callable $progress = null): ?string
    {
        if ($progress !== null) {
            $this->hashProgressCalls++;
            $progress($this->size($path), $this->size($path));
        }
        return $path === '/queue/unique.uax'
            ? 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
            : 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }

    public function delete(string $path): bool
    {
        if (!$this->exists($path)) {
            return false;
        }
        $this->existing[$path] = false;
        $this->deleted[] = $path;
        return true;
    }
}

$records = new TestRecordStore();
$files = new TestFileSystem();
$progress = [];
$service = new UnverifiedDuplicateCleanupService(
    new TestQueueInventory(),
    $records,
    $files
);

$scan = $service->scan(static function (array $state) use (&$progress): void {
    $progress[] = $state;
});
duplicate_expect($scan['physical_files'] === 4, 'Physical queue count changed.');
duplicate_expect($scan['hashed_files'] === 3, 'Only same-size candidates should be hashed.');
duplicate_expect($scan['duplicate_groups'] === 1, 'Expected one exact duplicate group.');
duplicate_expect($scan['duplicate_files'] === 2, 'Expected two removable duplicate files.');
duplicate_expect($scan['duplicate_bytes'] === 200, 'Duplicate byte total changed.');
duplicate_expect($files->hashProgressCalls === 3, 'Hash progress did not run for each same-size candidate.');
duplicate_expect(
    count(array_filter($progress, static fn(array $state): bool => ($state['stage'] ?? '') === 'hashing')) >= 3,
    'Chunked hash progress was not forwarded by the cleanup service.'
);
duplicate_expect(
    $scan['groups'][0]['keeper']['queue_name'] === 'indexed.utx',
    'An indexed queue copy must be retained before age ordering.'
);

$result = $service->deleteDuplicates();
duplicate_expect($result['deleted_files'] === 2, 'Expected two duplicate deletions.');
duplicate_expect($result['deleted_bytes'] === 200, 'Deleted byte total changed.');
duplicate_expect($result['errors'] === [], 'Unexpected cleanup errors.');
duplicate_expect(
    $files->deleted === ['/queue/oldest.utx', '/queue/newer.utx'],
    'Duplicate deletion order or keeper policy changed.'
);
duplicate_expect(
    $records->deleted === ['1:oldest.utx', '0:newer.utx'],
    'Database cleanup did not follow physical deletion.'
);

echo "Unverified duplicate service tests passed.\n";
