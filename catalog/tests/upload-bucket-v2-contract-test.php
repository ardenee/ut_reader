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
$workerCompatibility = file_get_contents($root . '/assets/upload-file-inspector-worker-compatible.js');
$navigation = file_get_contents($root . '/lib/CatalogNavigation.php');

foreach (compact('page', 'coordinator', 'worker', 'workerCompatibility', 'navigation') as $name => $source) {
    ub_v2_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

ub_v2_expect(
    str_contains($navigation, "'Upload Bucket (New)' => \$root . 'upload-bucket-v2.php'")
        && str_contains($navigation, "'Upload Bucket (Legacy)' => \$root . 'upload-bucket.php'"),
    'The new and legacy Upload Bucket pages are not both available from navigation.'
);

ub_v2_expect(
    str_contains($page, 'id="upload-bucket-stop"')
        && str_contains($page, 'id="upload-bucket-folder-button"')
        && str_contains($page, 'id="upload-bucket-errors-only" type="checkbox" checked')
        && str_contains($page, 'id="bucket-error-log"')
        && str_contains($page, 'data-inspector-worker-url=')
        && str_contains($page, 'upload-file-inspector-worker-compatible.js')
        && str_contains($page, 'upload-bucket-v2-coordinator.js')
        && !str_contains($page, 'upload-bucket-extension-filter.js')
        && str_contains($page, 'stores the uncompressed package')
        && str_contains($page, 'CHECKED : READY : UPLOADED : QUEUED : UPLOADED')
        && str_contains($page, '.uz accepts both historic 1234 and 5678'),
    'The new Upload Bucket page does not expose incremental folder selection, errors-only status, compact status or redirect policy.'
);

ub_v2_expect(
    str_contains($coordinator, 'window.showDirectoryPicker')
        && str_contains($coordinator, 'async function* walkDirectory')
        && str_contains($coordinator, 'await yieldToBrowser()')
        && str_contains($coordinator, 'pickedDirectoryEntries.push(entry)')
        && !str_contains($coordinator, 'Array.from(fileInput.files')
        && !str_contains($coordinator, 'new window.DataTransfer()'),
    'Large-folder discovery still constructs or copies a complete FileList on the page thread.'
);

ub_v2_expect(
    str_contains($coordinator, 'const logLines = []')
        && str_contains($coordinator, 'bucket-log-spacer')
        && str_contains($coordinator, 'bucket-log-viewport')
        && str_contains($coordinator, 'const start = Math.max(0, Math.floor(log.scrollTop / LOG_ROW_HEIGHT)')
        && str_contains($coordinator, 'window.requestAnimationFrame')
        && str_contains($coordinator, 'document.addEventListener(\'visibilitychange\'')
        && !str_contains($coordinator, 'log.appendChild(row)'),
    'The complete file log is not virtualised and display updates are not frame-throttled.'
);

ub_v2_expect(
    str_contains($coordinator, 'const worker = new Worker(workerUrl)')
        && str_contains($coordinator, 'const inspection = await inspectFile(file, name, index + 1, totalFiles, lineId)')
        && str_contains($coordinator, 'const checked = await preflight(file, name, inspection, lineId)')
        && str_contains($coordinator, 'const uploaded = await uploadFile(file, name, inspection, index + 1, totalFiles, lineId)')
        && str_contains($coordinator, 'const finalized = await finalizeOne(uploaded, lineId)')
        && str_contains($coordinator, 'for (let index = 0; index < totalFiles; index++)')
        && !str_contains($coordinator, 'Promise.all('),
    'The coordinator is not a strict one-file inspect/preflight/upload/finalise pipeline.'
);

ub_v2_expect(
    str_contains($coordinator, "appendStage(lineId, 'CHECKED'")
        && str_contains($coordinator, "appendStage(lineId, 'READY'")
        && str_contains($coordinator, "appendStage(lineId, 'UPLOADED'")
        && str_contains($coordinator, "appendStage(lineId, 'QUEUED'")
        && str_contains($coordinator, "finishLine(lineId, 'UPLOADED'")
        && str_contains($coordinator, "finishLine(lineId, 'SKIPPED'")
        && str_contains($coordinator, "finishLine(lineId, 'FAILED'"),
    'One per-file line does not accumulate the requested status stages and final result.'
);

ub_v2_expect(
    str_contains($coordinator, "stopButton.addEventListener('click'")
        && str_contains($coordinator, 'terminateInspector()')
        && str_contains($coordinator, 'activeXhr.abort()')
        && str_contains($coordinator, 'activeFetchController.abort()')
        && str_contains($coordinator, 'directoryScanActive'),
    'The Stop control does not cover folder discovery and active browser/server work.'
);

ub_v2_expect(
    str_contains($worker, 'class Md5')
        && str_contains($worker, 'class Sha1')
        && str_contains($worker, "extension === 'uz2'")
        && str_contains($worker, "extension === 'pak'")
        && str_contains($worker, 'Unreal package magic is missing')
        && str_contains($worker, "self.addEventListener('message'")
        && str_contains($workerCompatibility, "signature !== 1234 && signature !== 5678")
        && str_contains($workerCompatibility, "importScripts('upload-file-inspector-worker.js')"),
    'The file-inspection worker is missing hash, Unreal header or dual .uz signature checks.'
);

fwrite(STDOUT, "Upload Bucket v2 large-folder, compact-log, errors-only and dual-signature contract tests passed.\n");
