<?php
declare(strict_types=1);

function ub_v2_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/upload-bucket-v2.php');
$coordinator = file_get_contents($root . '/assets/upload-bucket-v2-coordinator.js');
$worker = file_get_contents($root . '/assets/upload-file-inspector-worker.js');
$navigation = file_get_contents($root . '/lib/CatalogNavigation.php');

foreach (compact('page', 'coordinator', 'worker', 'navigation') as $name => $source) {
    ub_v2_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

ub_v2_expect(
    str_contains($navigation, "'Upload Bucket (New)' => \$root . 'upload-bucket-v2.php'")
        && str_contains($navigation, "'Upload Bucket (Legacy)' => \$root . 'upload-bucket.php'"),
    'The new and legacy Upload Bucket pages are not both available from navigation.'
);

ub_v2_expect(
    str_contains($page, 'id="upload-bucket-stop"')
        && str_contains($page, 'data-inspector-worker-url=')
        && str_contains($page, 'upload-file-inspector-worker.js')
        && str_contains($page, 'upload-bucket-v2-coordinator.js')
        && str_contains($page, 'transferred in their compressed wrapper form')
        && str_contains($page, 'stores the uncompressed package'),
    'The new Upload Bucket page does not expose the worker, Stop control or redirect-wrapper policy.'
);

ub_v2_expect(
    str_contains($coordinator, "const worker = new Worker(workerUrl)")
        && str_contains($coordinator, 'await inspectFile(file, index + 1, files.length)')
        && str_contains($coordinator, 'await preflight(file, inspection)')
        && str_contains($coordinator, 'await uploadFile(file, inspection, index + 1, files.length)')
        && str_contains($coordinator, 'await finalizeOne(uploaded)')
        && str_contains($coordinator, 'for (let index = 0; index < files.length; index++)')
        && !str_contains($coordinator, 'Promise.all('),
    'The new coordinator is not a strict inspect/preflight/upload/finalise one-file pipeline.'
);

ub_v2_expect(
    str_contains($coordinator, "stopButton.addEventListener('click'")
        && str_contains($coordinator, 'activeInspector.terminate()')
        && str_contains($coordinator, 'activeXhr.abort()')
        && str_contains($coordinator, 'activeFetchController.abort()')
        && str_contains($coordinator, 'No later file will be inspected or uploaded'),
    'The Stop control does not abort the active browser work and prevent later files.'
);

ub_v2_expect(
    str_contains($worker, 'class Md5')
        && str_contains($worker, 'class Sha1')
        && str_contains($worker, "extension === 'uz2'")
        && str_contains($worker, 'signature !== 1234')
        && str_contains($worker, 'signature !== 5678')
        && str_contains($worker, "extension === 'pak'")
        && str_contains($worker, 'Unreal package magic is missing')
        && str_contains($worker, "self.addEventListener('message'"),
    'The file-inspection worker is missing hash or Unreal header checks.'
);

fwrite(STDOUT, "Upload Bucket v2 contract tests passed.\n");
