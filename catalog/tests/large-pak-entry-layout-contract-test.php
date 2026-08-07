<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies large PAK entry layout behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
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
    '$trustedImportConfig = $config;',
    '$trustedImportConfig[\'max_upload_bytes\'] = PHP_INT_MAX;',
    'new CatalogPakImportJobHandler($db, $trustedImportConfig)',
    'new CatalogStagedImportJobHandler($db, $trustedImportConfig)',
    'new CatalogSourceScanJobHandler($db, $trustedImportConfig)',
    'new GameBackupImportJobHandler($db, $trustedImportConfig)',
] as $fragment) {
    large_pak_entry_layout_expect(
        str_contains($factory, $fragment),
        'A trusted package import path still uses the normal upload-size ceiling: ' . $fragment
    );
}

$unverifiedAction = file_get_contents(__DIR__ . '/../unverified-files-action.php');
large_pak_entry_layout_expect(is_string($unverifiedAction), 'Could not read the unverified action endpoint.');
foreach ([
    '$trustedImportConfig = $config;',
    '$trustedImportConfig[\'max_upload_bytes\'] = PHP_INT_MAX;',
    'unverified_action_promote_item(',
] as $fragment) {
    large_pak_entry_layout_expect(
        str_contains($unverifiedAction, $fragment),
        'Large unverified package promotion is missing: ' . $fragment
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
    'pak-info-nowrap',
    'white-space: nowrap !important',
    '<th>Database</th>',
    'f.name_count,f.import_count,f.export_count',
    'title="Names / Imports / Exports"',
    '<a href="file-examine.php?id=',
    '<a href="file-info.php?id=',
] as $fragment) {
    large_pak_entry_layout_expect(str_contains($pakInfo, $fragment), 'PAK detail entry layout is missing: ' . $fragment);
}
large_pak_entry_layout_expect(
    !str_contains($pakInfo, 'examine extracted package'),
    'PAK details still show a separate examine extracted package link.'
);
large_pak_entry_layout_expect(
    !str_contains($pakInfo, '<th>Message</th>'),
    'PAK details still expose the removed Message column.'
);
large_pak_entry_layout_expect(
    !str_contains($pakInfo, "['import_message']"),
    'PAK details still render the removed per-entry message value.'
);
large_pak_entry_layout_expect(
    !str_contains($pakInfo, '<td class="nowrap">'),
    'PAK detail sizes still force the legacy no-wrap table column.'
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
