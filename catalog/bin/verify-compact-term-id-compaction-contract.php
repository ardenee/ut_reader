#!/usr/bin/env php
<?php
/** Static regression contract for sparse ue_terms ID compaction. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$tool = $read('bin/compact-ue-term-ids.php');
$lookup = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$search = $read('src/Infrastructure/Metadata/CompactSearchProjectionWriter.php');

$record(
    'bigint_rebuild_migration_is_not_pending',
    !is_file($root . '/migrations/202609040001_compact_term_ids_bigint.php'),
    'Sparse AUTO_INCREMENT exhaustion must be repaired by rekeying live IDs rather than rebuilding all projection tables as BIGINT.'
);

$record(
    'compaction_requires_offline_explicit_confirmation',
    str_contains($tool, '--offline-confirmed')
        && str_contains($tool, 'Stop Apache/public writes and Background Jobs workers')
        && str_contains($tool, 'Refusing term-ID compaction while'),
    'The rekey creates a temporary dictionary/reference mismatch, so public writes and workers must stay offline.'
);

$record(
    'all_known_term_reference_columns_are_rekeyed',
    str_contains($tool, "'ue_name_lookup'")
        && str_contains($tool, "'name_term_id'")
        && str_contains($tool, "'ue_export_lookup'")
        && str_contains($tool, "'object_term_id'")
        && str_contains($tool, "'class_term_id'")
        && str_contains($tool, "'local_path_term_id'")
        && str_contains($tool, "'ue_dependency_links'")
        && str_contains($tool, "'required_package_term_id'")
        && str_contains($tool, "'required_object_term_id'")
        && str_contains($tool, "'import_class_package_term_id'")
        && str_contains($tool, "'import_class_name_term_id'")
        && str_contains($tool, "'import_object_term_id'")
        && str_contains($tool, "'resolution_source_term_id'")
        && str_contains($tool, "'resolution_confidence_term_id'"),
    'Every persisted ue_terms reference in the current schema must move through the same old→new mapping.'
);

$record(
    'reference_chunks_and_resume_cursor_commit_together',
    str_contains($tool, '$db->beginTransaction();')
        && str_contains($tool, "'UPDATE ' . \$stateTable . ' SET phase=?,cursor_file_id=?,updated_at=NOW() WHERE id=1'")
        && str_contains($tool, '$db->commit();')
        && str_contains($tool, '--file-id-span=')
        && str_contains($tool, '--max-chunks='),
    'A crash must never leave an updated chunk with an old resume cursor that would remap already-dense IDs a second time.'
);

$record(
    'new_dictionary_is_dense_and_swapped_only_after_reference_phases',
    str_contains($tool, 'new_id INT UNSIGNED NOT NULL AUTO_INCREMENT')
        && str_contains($tool, "'next' => 'swap'")
        && str_contains($tool, 'RENAME TABLE ue_terms TO ')
        && str_contains($tool, 'ALTER TABLE ue_terms AUTO_INCREMENT='),
    'The compacted dictionary must use dense INT IDs and become active only after all reference phases finish.'
);

$record(
    'old_dictionary_is_retained_until_explicit_verify_cleanup',
    str_contains($tool, 'ue_terms_pre_compaction')
        && str_contains($tool, 'dictionary_values_match_old_via_map')
        && str_contains($tool, 'SET phase="verified"')
        && str_contains($tool, "\$db->exec('DROP TABLE ' . \$backupTermsTable);")
        && str_contains($tool, "\$db->exec('DROP TABLE ' . \$mapTable);"),
    'The sparse dictionary and mapping must remain available until a separate verification succeeds.'
);

$record(
    'live_verifier_has_valid_export_reference_sql',
    str_contains(
        $tool,
        "object_term_id,COALESCE(class_term_id,0),COALESCE(local_path_term_id,0))),0) "
    ),
    'The live verifier must close the outer COALESCE before FROM ue_export_lookup.'
);

$record(
    'live_verifier_requires_dense_next_auto_increment',
    str_contains($tool, "'auto_increment_is_dense_next_id' => $auto === ($termCount + 1)"),
    'Verification must not accept a dense dictionary that still carries the historically exhausted allocator counter.'
);

$rebase = $read('bin/rebase-ue-terms-auto-increment.php');
$record(
    'allocator_rebase_is_offline_verified_and_retained',
    str_contains($rebase, '--offline-confirmed')
        && str_contains($rebase, 'SHOW CREATE TABLE ue_terms')
        && str_contains($rebase, 'AUTO_INCREMENT=\\d+')
        && str_contains($rebase, 'ue_terms_pre_auto_increment_rebase')
        && str_contains($rebase, "'auto_increment_after'"),
    'MySQL 8 persistent AUTO_INCREMENT state must be rebuilt from a fresh table definition and retained until cleanup.'
);

$record(
    'current_writers_no_longer_burn_duplicate_ids',
    str_contains($lookup, '$this->resolveTermSet($terms, $terms, $resolved, $sqlBatches);')
        && str_contains($lookup, '$missing = array_filter(')
        && str_contains($search, '$this->resolveTermSet($terms, $terms, $resolved, $sqlBatches);')
        && str_contains($search, '$missing = array_filter('),
    'Compaction is durable only because normal publication now inserts genuinely missing terms rather than every duplicate term.'
);

$syntaxFailures = [];
foreach ([
    $root . '/bin/compact-ue-term-ids.php',
    $root . '/bin/rebase-ue-terms-auto-increment.php',
    $root . '/bin/verify-compact-term-id-compaction-contract.php',
    $root . '/bin/repair-ue-terms-auto-increment.php',
] as $file) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
