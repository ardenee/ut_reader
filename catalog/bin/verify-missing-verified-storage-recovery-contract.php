#!/usr/bin/env php
<?php
/** Verify safety properties of the missing verified-package recovery utility. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$path = $root . '/bin/recover-missing-verified-packages.php';
$source = @file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "Could not read recovery utility.\n");
    exit(1);
}

$checks = [];
$add = static function (string $check, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $check, 'ok' => $ok, 'detail' => $detail];
};

$add(
    'dry_run_by_default',
    str_contains($source, "\$apply = array_key_exists('apply', \$options);")
        && str_contains($source, 'if ($apply)'),
    'Recovery must require explicit --apply before copying bytes into canonical storage.'
);
$add(
    'exact_size_md5_sha1_required',
    str_contains($source, 'filesize($path)')
        && str_contains($source, 'md5_file($path)')
        && str_contains($source, 'sha1_file($path)'),
    'Every recovery source must match catalog size, MD5 and SHA1.'
);
$add(
    'database_identity_is_read_only',
    preg_match(
        '/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i',
        preg_replace('/\/\*.*?\*\/|\/\/[^\n]*|#[^\n]*/s', '', $source) ?: ''
    ) !== 1,
    'Recovery must not mutate ue_files or any other catalog table.'
);
$add(
    'atomic_destination_publication',
    str_contains($source, '.restore-')
        && str_contains($source, 'rename($temporary, $destination)'),
    'Recovered bytes must be verified in a temporary file before atomic publication.'
);
$add(
    'refuses_running_workers',
    str_contains($source, 'FROM ue_background_jobs WHERE status="running"')
        && str_contains($source, '--force-running-jobs'),
    'Apply mode must refuse concurrent background work unless explicitly overridden.'
);
$add(
    'all_or_nothing_default',
    str_contains($source, '--allow-partial')
        && str_contains($source, 'Apply is all-or-nothing by default'),
    'Apply mode must refuse unresolved sources unless partial recovery is explicitly requested.'
);
$add(
    'never_overwrites_nonmatching_canonical_file',
    str_contains($source, 'Refusing to overwrite a non-matching file already present at canonical path'),
    'Existing canonical bytes that disagree with catalog identity must be preserved for investigation.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(
    ['ok' => $ok, 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($ok ? 0 : 2);
