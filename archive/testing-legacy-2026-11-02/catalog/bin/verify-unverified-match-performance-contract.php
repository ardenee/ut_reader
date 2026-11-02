#!/usr/bin/env php
<?php
/**
 * Read-only source contract for the indexed Upload Bucket dependency-evidence hot path.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$queryPath = $root . '/src/Infrastructure/Unverified/PdoUnverifiedGameMatchQuery.php';
$metadataPath = $root . '/src/Infrastructure/Unverified/CatalogUnverifiedMetadataStore.php';
$queuePath = $root . '/src/Infrastructure/Unverified/CatalogUnverifiedGameMatchRefreshQueue.php';

$query = is_file($queryPath) ? file_get_contents($queryPath) : false;
$metadata = is_file($metadataPath) ? file_get_contents($metadataPath) : false;
$queue = is_file($queuePath) ? file_get_contents($queuePath) : false;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok];
    if (!$ok) {
        $failures[] = $name;
    }
};

$record('query_source_available', is_string($query));
$record('metadata_source_available', is_string($metadata));
$record('queue_source_available', is_string($queue));

if (is_string($query)) {
    $record(
        'dependency_lookup_uses_required_package_term_id',
        str_contains($query, 'WHERE l.required_package_term_id IN (')
    );
    $record(
        'required_package_terms_are_enumerated_once_from_compact_index',
        str_contains($query, 'SELECT required_package_term_id FROM ue_dependency_links')
            && str_contains($query, 'GROUP BY required_package_term_id ORDER BY NULL')
    );
    $record(
        'package_case_semantics_are_preserved_in_term_index',
        str_contains($query, '$packageKey = $this->key((string)$row[\'value_text\']);')
            && str_contains($query, '$byKey[$packageKey][$termId] = $termId;')
    );
    $record(
        'live_exact_term_resolution_uses_hash_and_length_index',
        str_contains($query, '(value_hash=? AND value_length=?)')
            && str_contains($query, 'md5($value, true)')
    );
    $record(
        'old_dependency_text_scan_removed_from_matcher',
        !str_contains($query, 'WHERE d.required_package IN (')
            && !str_contains($query, "'FROM ' . \$dependencySource . ' d '")
    );
    $record(
        'bulk_matcher_reuses_loaded_package_name_for_metadata',
        str_contains($query, '$this->metadata->load($fileId, (string)$file[\'package_name\'])')
    );
}

if (is_string($metadata)) {
    $record(
        'metadata_load_accepts_known_package_name',
        str_contains($metadata, 'public function load(int $fileId, ?string $knownPackageName = null): array')
    );
    $record(
        'metadata_load_falls_back_to_database_when_name_not_supplied',
        str_contains($metadata, 'if ($packageName === \'\')')
            && str_contains($metadata, "SELECT package_name FROM ue_files WHERE id=?")
    );
}

if (is_string($queue)) {
    $record(
        'durable_per_file_job_topology_is_unchanged',
        str_contains($queue, "['file_id' => \$fileId, 'scope' => 'file']")
            && str_contains($queue, "'unverified-game-match:file:' . \$fileId")
    );
}

foreach ([$queryPath, $metadataPath] as $path) {
    if (!is_file($path) || !function_exists('proc_open')) {
        $record('syntax:' . basename($path), false);
        continue;
    }
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        $record('syntax:' . basename($path), false);
        continue;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $record('syntax:' . basename($path), proc_close($process) === 0);
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
