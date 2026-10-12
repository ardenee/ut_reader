#!/usr/bin/env php
<?php
/**
 * Isolated, bounded name-candidate surrogate-ID prototype.
 *
 * Session-local TEMPORARY tables only. The 14 representative file IDs are
 * accessed via the existing PRIMARY KEY; the full candidate corpus is never scanned.
 * No persistent schema/data is changed and no production index is rebuilt.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';

$opts = getopt('', ['rows::', 'repetitions::']);
$limit = max(1, min(20000, (int)($opts['rows'] ?? 10000)));
$repetitions = max(1, min(20, (int)($opts['repetitions'] ?? 3)));
$ids = [111139, 1363209, 113253, 1362900, 148919, 1360665,
    111460, 1363138, 112300, 1362977, 113526, 1359117, 110902, 1362870];
$namedIds = implode(',', array_map('intval', $ids));
$db = null;
$summary = ['ok'=>false, 'session_temporary_tables_only'=>true,
    'live_candidate_tables_unchanged'=>true, 'sample_file_ids'=>$ids, 'row_limit'=>$limit];
try {
    $db = catalog_bootstrap(false)->db;
    $db->exec('CREATE TEMPORARY TABLE u5_sample_name_source (
        file_id BIGINT UNSIGNED NOT NULL,
        name_key_hash BINARY(16) NOT NULL,
        name_key_length INT UNSIGNED NOT NULL,
        name_key_fingerprint BINARY(32) NOT NULL,
        first_name_index INT UNSIGNED NOT NULL,
        PRIMARY KEY(file_id,name_key_fingerprint),
        KEY by_name(name_key_hash,name_key_length,name_key_fingerprint,file_id)
    ) ENGINE=InnoDB');
    $db->exec('CREATE TEMPORARY TABLE u5_sample_name_dictionary (
        name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name_key_hash BINARY(16) NOT NULL,
        name_key_length INT UNSIGNED NOT NULL,
        name_key_fingerprint BINARY(32) NOT NULL,
        PRIMARY KEY(name_id),
        UNIQUE KEY fingerprint(name_key_fingerprint),
        KEY resolve_hash(name_key_hash,name_key_length,name_key_fingerprint)
    ) ENGINE=InnoDB');
    $db->exec('CREATE TEMPORARY TABLE u5_sample_name_compact (
        file_id BIGINT UNSIGNED NOT NULL,
        name_id BIGINT UNSIGNED NOT NULL,
        first_name_index INT UNSIGNED NOT NULL,
        PRIMARY KEY(file_id,name_id),
        KEY by_name(name_id,file_id)
    ) ENGINE=InnoDB');

    // A limit on this indexed access prevents accidental full-catalogue work.
    $db->exec('INSERT INTO u5_sample_name_source
       (file_id,name_key_hash,name_key_length,name_key_fingerprint,first_name_index)
       SELECT file_id,name_key_hash,name_key_length,name_key_fingerprint,first_name_index
       FROM ue_uedb5_name_candidates
       WHERE file_id IN ('.$namedIds.')
       ORDER BY file_id,name_key_fingerprint LIMIT '.$limit);
    $db->exec('INSERT INTO u5_sample_name_dictionary
       (name_key_hash,name_key_length,name_key_fingerprint)
       SELECT name_key_hash,name_key_length,name_key_fingerprint
       FROM u5_sample_name_source GROUP BY name_key_hash,name_key_length,name_key_fingerprint');
    $db->exec('INSERT INTO u5_sample_name_compact (file_id,name_id,first_name_index)
       SELECT s.file_id,d.name_id,s.first_name_index
       FROM u5_sample_name_source s JOIN u5_sample_name_dictionary d
       ON d.name_key_fingerprint=s.name_key_fingerprint
       AND d.name_key_hash=s.name_key_hash
       AND d.name_key_length=s.name_key_length');

    $count = static fn(string $table): int =>
        (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $sourceRows = $count('u5_sample_name_source');
    $compactRows = $count('u5_sample_name_compact');
    $dictionaryRows = $count('u5_sample_name_dictionary');
    if ($sourceRows === 0 || $sourceRows !== $compactRows) {
        throw new RuntimeException('Shadow cardinality mismatch or empty sample.');
    }

    $queries = $db->query('SELECT name_key_fingerprint,name_key_hash,name_key_length
       FROM u5_sample_name_dictionary ORDER BY name_id LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    $old = $db->prepare('SELECT file_id,first_name_index FROM u5_sample_name_source
       WHERE name_key_hash=? AND name_key_length=? AND name_key_fingerprint=?
       ORDER BY file_id,first_name_index');
    $new = $db->prepare('SELECT c.file_id,c.first_name_index FROM u5_sample_name_dictionary d
       JOIN u5_sample_name_compact c ON c.name_id=d.name_id
       WHERE d.name_key_hash=? AND d.name_key_length=? AND d.name_key_fingerprint=?
       ORDER BY c.file_id,c.first_name_index');
    $checks = 0; $mismatches = []; $oldSeconds = 0.0; $newSeconds = 0.0;
    foreach ($queries as $key) {
        $args = [(string)$key['name_key_hash'],(int)$key['name_key_length'],
            (string)$key['name_key_fingerprint']];
        for ($i=0; $i<$repetitions; $i++) {
            $start = microtime(true);
            $old->execute($args);
            $original = $old->fetchAll(PDO::FETCH_NUM);
            $oldSeconds += microtime(true) - $start;
            $start = microtime(true);
            $new->execute($args);
            $compact = $new->fetchAll(PDO::FETCH_NUM);
            $newSeconds += microtime(true) - $start;
            if ($original !== $compact) {
                $mismatches[] = ['fingerprint'=>bin2hex($args[2]),'round'=>$i,
                    'old_count'=>count($original),'new_count'=>count($compact)];
            }
            $checks++;
        }
    }

    $summary += ['sample_rows'=>$sourceRows,'dictionary_rows'=>$dictionaryRows,
        'compact_rows'=>$compactRows, 'exact_lookup_comparisons'=>$checks,
        'mismatches'=>$mismatches,'old_query_seconds'=>round($oldSeconds,6),
        'new_query_seconds'=>round($newSeconds,6),
        'old_logical_bytes_per_row'=>64,'new_logical_bytes_per_row'=>20,
        'estimated_sample_field_bytes_saved'=>$sourceRows * 44,
        'warning'=>'Logical field width and small-sample query timings are not disk-size or full-workload benchmarks.'];
    $summary['ok'] = $mismatches === [];
} catch (Throwable $e) {
    $summary['error'] = $e->getMessage();
} finally {
    if ($db instanceof PDO) {
        foreach (['u5_sample_name_compact','u5_sample_name_dictionary','u5_sample_name_source'] as $table) {
            try { $db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table); } catch (Throwable) {}
        }
    }
}
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($summary['ok']?0:2);
