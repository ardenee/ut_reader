<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;
use RuntimeException;

/**
 * Parses the opt-in UE5.8.3 UEDB_RT source probe; NEVER certifies a live
 * engine or turns a partial compliance operation into verified.
 *
 * Logs are untrusted, including their provenance/build claims. The report
 * intentionally contains no UObject paths; only counts and anonymized errors.
 */
final class Uedb5Ue5ScriptRuntimeTraceInspector
{
    public const PINNED_EPIC_SHA = '43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9';
    private const MAX_LOG_BYTES = 134217728;
    private const MAX_EVENTS = 500000;
    private const ALLOWED = [
        'lookup'=>['event','index','found'],
        'add'=>['event','kind','index','path'],
        'remove_gc'=>['event','index','path'],
        'add_cell'=>['event','index'],
        'name'=>['event','branch','index'],
        'eligible_package'=>['event','kind','path'],
        'excluded'=>['event','reason','path'],
        'registration_complete'=>['event','epic_sha','editor','check','debug','count'],
    ];
    private const ADD_KINDS = [
        'editor_package','initial_object','nested_public',
        'enumerated_editor_package','enumerated_public'
    ];
    private const NAME_BRANCHES = ['live','cooked_diagnostic','missing'];

    /** @return array<string,mixed> */
    public static function inspectFile(string $file): array
    {
        if (!is_file($file) || !is_readable($file) || !is_int(filesize($file))
            || filesize($file) > self::MAX_LOG_BYTES) {
            throw new InvalidArgumentException('A readable local text log of at most 128 MiB is required.');
        }
        $stream = fopen($file, 'rb');
        if ($stream === false) throw new RuntimeException('Could not open local trace.');
        $events = []; $malformed = []; $eventCount=0;
        $counts=[]; $adds=[]; $observedCells=[]; $seenNames=[];
        $snapshots=[]; $lookups=[]; $relations=[]; $rejections=[];
        $logLines=0;
        try {
            while (($line=fgets($stream,65536)) !== false) {
                $logLines++;
                if (!str_contains($line,'UEDB_RT|')) continue;
                $eventCount++;
                if ($eventCount > self::MAX_EVENTS) {
                    throw new InvalidArgumentException('Trace exceeds the bounded 500000-event inspection limit.');
                }
                $pos=strpos($line,'UEDB_RT|');
                $payload=trim(substr($line,$pos+strlen('UEDB_RT|')));
                $fields=[];
                foreach (explode('|',$payload) as $field) {
                    if (!preg_match('/^([a-z_]+)=(.*)$/D',$field,$match)
                        ||array_key_exists($match[1],$fields)) {
                        $malformed[]=['line'=>$logLines,'reason'=>'field_format_or_duplicate'];
                        continue 2;
                    }
                    $fields[$match[1]]=$match[2];
                }
                $kind=$fields['event']??'';
                $allowed=self::ALLOWED[$kind]??null;
                if ($allowed===null || array_keys($fields)!==$allowed) {
                    $malformed[]=['line'=>$logLines,'reason'=>'unexpected_event_or_field_schema'];
                    continue;
                }
                $index=$fields['index']??null;
                if ($index!==null && !preg_match('/^[0-9A-F]{16}$/D',$index)) {
                    $malformed[]=['line'=>$logLines,'reason'=>'invalid_hex_index'];
                    continue;
                }
                if (isset($fields['path']) && ($fields['path']==='' || strlen($fields['path']) > 8192
                    ||preg_match('//u',$fields['path'])!==1)) {
                    $malformed[]=['line'=>$logLines,'reason'=>'invalid_or_oversize_utf8_path'];
                    continue;
                }
                $legal=match($kind) {
                    'lookup' => in_array($fields['found'],['0','1'],true)
                        && preg_match('/^[4-7][0-9A-F]{15}$/D',$index)===1,
                    'add' => in_array($fields['kind'],self::ADD_KINDS,true)
                        && preg_match('/^[4-7][0-9A-F]{15}$/D',$index)===1,
                    'remove_gc' => preg_match('/^[4-7][0-9A-F]{15}$/D',$index)===1,
                    'add_cell' => preg_match('/^[4-7][0-9A-F]{15}$/D',$index)===1,
                    'name' => in_array($fields['branch'],self::NAME_BRANCHES,true),
                    'eligible_package' => in_array($fields['kind'],['script','verse_vni'],true),
                    'excluded' => $fields['reason']==='verse_vni_non_uht',
                    'registration_complete' =>
                        preg_match('/^[a-f0-9]{64}$/D',$fields['epic_sha'])===1
                        &&in_array($fields['editor'],['0','1'],true)
                        &&in_array($fields['check'],['0','1'],true)
                        &&in_array($fields['debug'],['0','1'],true)
                        &&preg_match('/^(0|[1-9][0-9]{0,8})$/D',$fields['count'])===1,
                    default => false
                };
                if (!$legal) {
                    $malformed[]=['line'=>$logLines,'reason'=>'event_semantics_rejected'];
                    continue;
                }
                // For all UObject and UPackage add keys, independently recompute
                // the *typed* ScriptImport identity from the exact path emitted.
                if ($kind==='add' || $kind==='remove_gc') {
                    try {
                        $hash=Uedb5Ue5ScriptImportPathHasher::fromObjectPath($fields['path']);
                        $expected=strtoupper(dechex(hexdec($hash[0])+4)).substr($hash,1);
                        if ($index!==$expected) {
                            $rejections[]=['line'=>$logLines,'reason'=>'typed_script_import_hash_disagrees_with_path'];
                            continue;
                        }
                    } catch (\Throwable) {
                        $rejections[]=['line'=>$logLines,'reason'=>'script_path_not_hashable'];
                        continue;
                    }
                }
                if ($kind==='eligible_package') {
                    $path=$fields['path'];
                    if (($fields['kind']==='script' && !str_starts_with($path,'/Script/'))
                        ||($fields['kind']==='verse_vni' && !str_contains($path,'/_Verse/VNI/'))) {
                        $rejections[]=['line'=>$logLines,'reason'=>'package_category_disagrees_with_epic_path_rule'];
                        continue;
                    }
                }
                $counter=$kind;
                if ($kind==='add') $counter.=':'.$fields['kind'];
                if ($kind==='name') $counter.=':'.$fields['branch'];
                if ($kind==='eligible_package') $counter.=':'.$fields['kind'];
                $counts[$counter]=($counts[$counter]??0)+1;
                if ($kind==='add') {
                    // Duplicated path->hash identities can be legal during editor
                    // reconstruction, so retain all observations but never infer a winner.
                    $adds[$index][]=['line'=>$logLines,'path'=>$fields['path'],'kind'=>$fields['kind']];
                }
                if ($kind==='remove_gc') $rejections[]=['line'=>$logLines,'reason'=>'gc_removal_observed_requires_live_lifecycle_review'];
                if ($kind==='add_cell') $observedCells[$index]=true;
                if ($kind==='lookup') $lookups[$fields['found']]=($lookups[$fields['found']]??0)+1;
                if ($kind==='name') $seenNames[$fields['branch']]=($seenNames[$fields['branch']]??0)+1;
                if ($kind==='registration_complete') {
                    $snapshots[]=[
                        'source_sha_match'=>$fields['epic_sha']===self::PINNED_EPIC_SHA,
                        'editor'=>(int)$fields['editor'],'check'=>(int)$fields['check'],
                        'debug'=>(int)$fields['debug'],'script_object_count'=>(int)$fields['count']
                    ];
                }
            }
        } finally { fclose($stream); }
        $collisions=0;
        foreach ($adds as $entries) {
            $distinct=array_unique(array_column($entries,'path'));
            if (count($distinct)>1) $collisions++;
        }
        $sourceMatch=$snapshots!==[] && !in_array(false,array_column($snapshots,'source_sha_match'),true);
        $reportedConsistent=true;
        foreach ($snapshots as $session) {
            if ($session['editor']===0 && $session['check']===0
                &&(($counts['add:enumerated_public']??0)>0
                   ||($counts['add:enumerated_editor_package']??0)>0)) {
                $reportedConsistent=false;
            }
            if ($session['editor']===0 &&
                (($counts['add:enumerated_public']??0)>0
                 ||($counts['add:enumerated_editor_package']??0)>0)) {
                $reportedConsistent=false;
            }
            if ($session['debug']===0 && ($seenNames['cooked_diagnostic']??0)>0) {
                $reportedConsistent=false;
            }
        }
        $valid=$eventCount>0 &&$malformed===[] &&
            !self::hasFatalRejections($rejections) &&
            $sourceMatch &&count($snapshots)===1 &&$reportedConsistent;
        return [
            'ok'=>$valid,
            'purpose'=>'untrusted_runtime_trace_shape_review_only',
            'runtime_origin_attested'=>false,
            'live_engine_behavior_independently_verified'=>false,
            'original_operation_dispositions_unchanged'=>true,
            'original_operations_remaining_partial'=>[
                'UE5-583-SCRIPT-003','UE5-583-SCRIPT-004',
                'UE5-583-SCRIPT-005','UE5-583-B015-1'
            ],
            'log_sha256'=>hash_file('sha256',$file),
            'source_marker_matches_pinned_ue583'=>$sourceMatch,
            'registration_complete_events'=>count($snapshots),
            'snapshots'=>$snapshots,
            'events_seen'=>$eventCount,
            'events_passed'=>$eventCount-count($malformed)-count(array_filter($rejections,
                static fn(array $e):bool=>$e['reason']!=='gc_removal_observed_requires_live_lifecycle_review')),
            'event_counts'=>$counts,
            'live_lookups_reported'=>$lookups,
            'diagnostic_names_reported'=>$seenNames,
            'distinct_registered_indices_seen'=>count($adds),
            'distinct_verse_cell_indices_seen'=>count($observedCells),
            'hash_collision_indices_reported'=>$collisions,
            'malformed_event_count'=>count($malformed),
            'rejected_semantic_event_count'=>count(array_filter($rejections,
                static fn(array $e):bool=>$e['reason']!=='gc_removal_observed_requires_live_lifecycle_review')),
            'errors'=>array_slice(array_merge($malformed, array_values(array_filter($rejections,
                static fn(array $e):bool=>$e['reason']!=='gc_removal_observed_requires_live_lifecycle_review'))),0,15),
            'manual_review_required'=>[
                'Verify physical binary build/hash and trace provenance independently',
                'Compare observed source paths/keys and editor/noneditor build settings with original Epic source',
                'Prove actual live UObject and UHT/Verse behavior before accepting any original operation'
            ]
        ];
    }

    private static function hasFatalRejections(array $rows):bool
    {
        foreach ($rows as $row) {
            if ($row['reason']!=='gc_removal_observed_requires_live_lifecycle_review') return true;
        }
        return false;
    }
}
