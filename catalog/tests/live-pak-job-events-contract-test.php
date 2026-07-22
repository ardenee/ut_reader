<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/Jobs/CatalogJobEventLog.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobEventLog;

function live_pak_events_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function live_pak_events_delete_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($path);
}

$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-live-pak-events-' . bin2hex(random_bytes(6));
try {
    $log = new CatalogJobEventLog(['storage_path' => $storage]);
    $log->reset(123);
    $log->append(123, [
        'status' => 'verified',
        'file' => 'Game/Content/One.uasset',
        'message' => 'Imported.',
        'file_id' => 10,
        'pak_entry_id' => 20,
    ]);
    $log->append(123, [
        'status' => 'duplicate',
        'file' => 'Game/Content/Two.uasset',
        'message' => 'Duplicate in selected game',
        'file_id' => 11,
        'pak_entry_id' => 21,
    ]);

    $first = $log->readFrom(123, 0, 1);
    live_pak_events_expect(count($first['events']) === 1, 'The first live event page did not contain one event.');
    live_pak_events_expect($first['has_more'] === true, 'The live event reader did not report the second event.');
    live_pak_events_expect((string)$first['events'][0]['status'] === 'verified', 'The first live event status changed.');

    $second = $log->readFrom(123, (int)$first['offset'], 10);
    live_pak_events_expect(count($second['events']) === 1, 'The second live event page did not contain one event.');
    live_pak_events_expect($second['has_more'] === false, 'The live event reader reported events after the end of the stream.');
    live_pak_events_expect((string)$second['events'][0]['status'] === 'duplicate', 'The second live event status changed.');

    $log->reset(123);
    $empty = $log->readFrom(123, (int)$second['offset'], 10);
    live_pak_events_expect($empty['events'] === [], 'Resetting a retried job did not clear its previous event stream.');
    live_pak_events_expect($log->remove(123), 'The live event stream could not be removed.');

    $handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogPakImportJobHandler.php');
    live_pak_events_expect(is_string($handler), 'Could not read the PAK import handler.');
    foreach (['new CatalogJobEventLog(', 'recordEvent(', "'status' => 'skipped'", "'status' => \$status"] as $fragment) {
        live_pak_events_expect(str_contains($handler, $fragment), 'PAK handler live event support is missing: ' . $fragment);
    }

    $statusEndpoint = file_get_contents(__DIR__ . '/../api/v1/job-status.php');
    live_pak_events_expect(is_string($statusEndpoint), 'Could not read the job status endpoint.');
    foreach (['event_offset', 'event_limit', 'events_has_more', 'CatalogJobEventLog'] as $fragment) {
        live_pak_events_expect(str_contains($statusEndpoint, $fragment), 'Job status live event support is missing: ' . $fragment);
    }

    $client = file_get_contents(__DIR__ . '/../assets/profiled-upload-jobs.js');
    live_pak_events_expect(is_string($client), 'Could not read the profiled upload client.');
    foreach (['appendSnapshotEvents', 'streamedEntries', "event_limit: '500'", 'while (snapshot.eventsHasMore)'] as $fragment) {
        live_pak_events_expect(str_contains($client, $fragment), 'Profiled upload live rendering is missing: ' . $fragment);
    }

    $cleanup = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogBackgroundJobCleanup.php');
    live_pak_events_expect(is_string($cleanup), 'Could not read background-job cleanup.');
    live_pak_events_expect(str_contains($cleanup, '$eventLog->remove('), 'Background-job cleanup does not remove event streams.');
} finally {
    live_pak_events_delete_tree($storage);
}

echo "Live PAK job event streaming contract tests passed.\n";
