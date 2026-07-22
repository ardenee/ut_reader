<?php
declare(strict_types=1);

function large_pak_entry_layout_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
large_pak_entry_layout_expect(is_string($factory), 'Could not read the job worker factory.');
foreach ([
    "$pakImportConfig = $config;",
    "$pakImportConfig['max_upload_bytes'] = PHP_INT_MAX;",
    'new CatalogPakImportJobHandler($db, $pakImportConfig)',
] as $fragment) {
    large_pak_entry_layout_expect(
        str_contains($factory, $fragment),
        'PAK entry imports still use the normal upload-size ceiling: ' . $fragment
    );
}

$queue = file_get_contents(__DIR__ . '/../src/Infrastructure/Import/CatalogProfiledUploadQueue.php');
large_pak_entry_layout_expect(is_string($queue), 'Could not read the profiled upload queue.');
large_pak_entry_layout_expect(
    str_contains($queue, '$limit = $isPak ? $this->containerLimitBytes() : (int)($this->config[\'max_upload_bytes\'] ?? 0);'),
    'Upload ingress limits were removed instead of limiting only accepted uploads/containers.'
);

$pakInfo = file_get_contents(__DIR__ . '/../pak-info.php');
large_pak_entry_layout_expect(is_string($pakInfo), 'Could not read PAK detail page.');
foreach ([
    'pak-info-natural-table',
    'pak-info-table-region',
    'white-space: normal !important',
    'overflow-wrap: anywhere',
    'pak-info-notes',
] as $fragment) {
    large_pak_entry_layout_expect(str_contains($pakInfo, $fragment), 'PAK detail wrapping is missing: ' . $fragment);
}
large_pak_entry_layout_expect(
    !str_contains($pakInfo, '<td class="nowrap">'),
    'PAK detail sizes still force a no-wrap table column.'
);

$fileInfo = file_get_contents(__DIR__ . '/../file-info.php');
large_pak_entry_layout_expect(is_string($fileInfo), 'Could not read file detail page.');
large_pak_entry_layout_expect(
    str_contains($fileInfo, '<pre class="mono">'),
    'File detail scan notes no longer use the shared monospaced note block.'
);

$uiCss = file_get_contents(__DIR__ . '/../assets/catalog-ui.css');
large_pak_entry_layout_expect(is_string($uiCss), 'Could not read shared UI stylesheet.');
foreach ([
    'pre.mono',
    'white-space: pre-wrap',
    'overflow-wrap: anywhere',
    'word-break: break-word',
] as $fragment) {
    large_pak_entry_layout_expect(str_contains($uiCss, $fragment), 'Scanner note wrapping is missing: ' . $fragment);
}

echo "Large PAK entry and detail layout contract tests passed.\n";
