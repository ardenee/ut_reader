<?php
declare(strict_types=1);
/**
 * Read-only consistency verifier for the compliance recovery register.
 * This is a coverage/accountability check, NOT a proof of Epic behavioral parity.
 */
if (PHP_SAPI !== 'cli') { exit(2); }
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry;
$root = dirname(__DIR__, 2);
$baselinePath = $root.'/catalog/compliance/RECOVERY.json';
$strict = in_array('--strict', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--baseline=')) {
        $baselinePath = substr($arg, strlen('--baseline='));
    }
}
$errors = [];
$require = static function (bool $ok, string $message) use (&$errors): void {
    if (!$ok) { $errors[] = $message; }
};
$load = static function (string $path) use ($require): array {
    $text = @file_get_contents($path);
    $parsed = is_string($text) ? json_decode($text, true) : null;
    $require(is_array($parsed), 'Missing or invalid JSON: '.$path);
    return is_array($parsed) ? $parsed : [];
};
$baseline = $load($baselinePath);
$require(($baseline['schema'] ?? null) === 1, 'Unsupported recovery schema.');
$require(($baseline['scope_complete'] ?? null) === false || ($baseline['scope_complete'] ?? null) === true,
    'Recovery scope_complete must be explicit.');
$require(in_array($baseline['compliance_claim'] ?? null, ['unverified','accepted'], true),
    'Recovery compliance_claim is invalid.');
if (($baseline['scope_complete']??false)===true) {
    $require(!empty($baseline['scope_reconciliation_evidence']),
        'Complete source scope requires independent scope reconciliation evidence.');
}
$manifests = glob($root.'/catalog/compliance/batches/*.json') ?: [];
$operations = [];
foreach ($manifests as $file) {
    $json = $load($file);
    foreach ((array)($json['operations'] ?? []) as $op) {
        $id = (string)($op['id'] ?? '');
        $require($id !== '' && !isset($operations[$id]),
            'Duplicate or missing formal operation: '.$id);
        if ($id !== '') { $op['recovery_gate_status']=(array)($json['gate_status']??[]); $operations[$id] = $op; }
    }
}
$priority = (array)($baseline['original_operation_priority'] ?? []);
$require(array_is_list($priority), 'Original operation priority must be a list.');
$require(count($priority) === count(array_unique($priority)),
    'Original operation priority repeats an operation.');
$missingPriority = array_values(array_diff(array_keys($operations), $priority));
$unknownPriority = array_values(array_diff($priority, array_keys($operations)));
$require($missingPriority === [], 'Formal operations omitted from recovery priority: '.implode(',', $missingPriority));
$require($unknownPriority === [], 'Recovery priority references unknown operations: '.implode(',', $unknownPriority));
$profiles = (array)($baseline['game_profiles'] ?? []);
$gameIds = []; $sourceKeys = []; $profileOpen = 0;
foreach ($profiles as $profile) {
    $id = (int)($profile['game_id'] ?? 0);
    $key = (string)($profile['source_key'] ?? '');
    $require($id > 0 && !isset($gameIds[$id]), 'Invalid or duplicate game profile: '.$id);
    $require($key !== '' && !isset($sourceKeys[$key]), 'Duplicate/empty source key: '.$key);
    $require(in_array($profile['engine'] ?? '', ['UE1','UE2','UE3','UE4','UE5'], true),
        'Unknown engine for game '.$id);
    $require(trim((string)($profile['source_baseline'] ?? '')) !== '',
        'Missing historical source baseline for game '.$id);
    $require(($profile['source_inventory'] ?? null) !== 'accepted'
        || ($baseline['scope_complete'] ?? false) === true,
        'Game inventory accepted while scope remains incomplete: '.$id);
    if (($profile['source_inventory']??'')==='accepted') {
        $require(!empty($profile['source_branch_inventory_evidence'])
            && !empty($profile['independent_source_review']),
            'Accepted game lacks source branch inventory/review evidence: '.$id);
    }
    if (($profile['source_inventory'] ?? 'not_reconciled') !== 'accepted') { $profileOpen++; }
    $gameIds[$id] = true; $sourceKeys[$key] = true;
    try {
        $require(Uedb5GameSourceRegistry::sourceKey($id) === $key,
            'Wrong source key for registered game '.$id);
    } catch (Throwable) {
        $require(false, 'Game profile is absent from UEDB5 source registry: '.$id);
    }
}
$expectedIds = Uedb5GameSourceRegistry::gameIds();
$require(array_diff($expectedIds, array_keys($gameIds)) === []
    && array_diff(array_keys($gameIds), $expectedIds) === [],
    'Recovery game coverage does not equal active UEDB5 game registry.');
$extraFormats = (array)($baseline['additional_format_targets'] ?? []);
$require(count($extraFormats) >= 2, 'UE4 and UE5 generic format targets must be explicitly listed.');
$requiredPaths = ['identify','read_decode','extract_import_export','discover_dependencies',
    'select_physical_providers','resolve_object_references','stage_v5','dependency_pass2',
    'persist_sql_projections','metadata_query_search','upload','archive_import',
    'backup_restore','resync','repair','validation'];
$paths = []; $unverifiedPaths = 0;
foreach ((array)($baseline['required_workflow_paths'] ?? []) as $path) {
    $name = (string)($path['name'] ?? '');
    $require($name !== '' && !isset($paths[$name]), 'Duplicate or missing workflow path '.$name);
    $coverage = (string)($path['coverage'] ?? '');
    $require(in_array($coverage,['unverified','mapped','accepted'],true),
        'Invalid workflow path coverage: '.$name);
    if ($coverage !== 'accepted') { $unverifiedPaths++; }
    if ($coverage === 'accepted') {
        $require(!empty($path['source_to_entry_point_map'])
            && !empty($path['end_to_end_acceptance_evidence']),
            'Accepted workflow path lacks mapped source and end-to-end proof: '.$name);
    }
    $paths[$name] = true;
}
$require(array_diff($requiredPaths,array_keys($paths)) === [],
    'Required processing routes omitted: '.implode(',',array_diff($requiredPaths,array_keys($paths))));
$ledgerText = @file_get_contents($root.'/catalog/compliance/DIFFERENCES.md');
$require(is_string($ledgerText), 'Differences ledger missing.');
preg_match_all('/^\\|\\s*(UE[1-5]-D[0-9]{3})\\s*\\|/m',
    (string)$ledgerText,$matches);
$ledgerIds = $matches[1] ?? [];
$require(count($ledgerIds)===count(array_unique($ledgerIds)),
    'Duplicate difference IDs in human ledger.');
$maps = (array)($baseline['known_differences'] ?? []);
$diffIds=[];$byType=[];$unregistered=[];$openDiffs=[];
foreach ($maps as $entry) {
    $id=(string)($entry['id']??'');
    $kind=(string)($entry['kind']??'');
    $parent=(array)($entry['operations']??[]);
    $state=(string)($entry['state']??'');
    $require(preg_match('/^UE[1-5]-D[0-9]{3}$/D',$id)===1 && !isset($diffIds[$id]),
        'Invalid or duplicate difference ID: '.$id);
    $require(in_array($kind,['mapped','cross_cutting','audit_control','unregistered_source_behavior'],true),
        'Invalid mapping type for '.$id);
    $require(in_array($state,['evidence_partial','fixed_regression_verified','accepted'],true),
        'Invalid recovery evidence state for '.$id);
    if($state==='fixed_regression_verified'){
        $require($kind==='mapped' && count($parent)>0,
            'Regression-verified source correction lacks original parent: '.$id);
        foreach($parent as $related){
            $original=(array)($operations[$related]??[]);
            $manifestGates=(array)($original['recovery_gate_status']??[]);
            // The source contract and implementation evidence are declared
            // within the original operation, and the formal verifier runs
            // every pinned branch test before accepting these gate flags.
            $require(($manifestGates['contracts']??false)===true
                && ($manifestGates['implementation']??false)===true
                && !empty($original['source_contract_evidence'])
                && !empty($original['correction_commits'])
                && !empty($original['source_contract_evidence']['expected_checks']),
                'Fixed source difference has no original-operation verification: '.$id);
        }
    }
    if ($state==='accepted') {
        $require(!empty($entry['independent_acceptance_evidence'])
            && !empty($entry['correction_or_waiver_evidence']),
            'Difference accepted without independent outcome evidence: '.$id);
    }
    $require(trim((string)($entry['rationale']??''))!=='',
        'Difference lacks mapping rationale: '.$id);
    $require(count($parent)===count(array_unique($parent)),
        'Repeated parent operation for '.$id);
    if (in_array($kind,['mapped','cross_cutting'],true)) {
        $require(count($parent)>0,'Difference has no parent operation: '.$id);
    } else {
        $require($parent===[],'Non-mapped difference falsely owns a source operation: '.$id);
    }
    foreach ($parent as $op) {
        $require(isset($operations[$op]),'Difference '.$id.' references nonexistent operation '.$op);
    }
    if ($kind==='unregistered_source_behavior'){$unregistered[]=$id;}
    if ($state!=='accepted') {$openDiffs[]=$id;}
    $byType[$kind]=($byType[$kind]??0)+1;
    $diffIds[$id]=true;
}
$require(array_diff($ledgerIds,array_keys($diffIds))===[],
    'Ledger differences absent from recovery: '.implode(',',array_diff($ledgerIds,array_keys($diffIds))));
$require(array_diff(array_keys($diffIds),$ledgerIds)===[],
    'Recovery differences absent from ledger: '.implode(',',array_diff(array_keys($diffIds),$ledgerIds)));
// Two-way traceability: the original formal operation must point back to
// every recovery-linked difference. A new fix cannot remain detached from the
// operation it was supposed to complete.
$expectedRelated=[];
foreach ($maps as $entry) {
    foreach ((array)($entry['operations']??[]) as $op) {
        $expectedRelated[$op][]=(string)$entry['id'];
    }
}
foreach ($operations as $id=>$op) {
    $actual=(array)($op['related_differences']??[]);
    $wanted=$expectedRelated[$id]??[];
    $require(count($actual)===count(array_unique($actual)),
        'Original operation repeats related difference: '.$id);
    sort($actual,SORT_STRING);
    sort($wanted,SORT_STRING);
    $require($actual===$wanted,
        'Original operation/difference reciprocity is incomplete: '.$id
        .' expected '.implode(',',$wanted).' got '.implode(',',$actual));
}
$gaps=(array)($baseline['coverage_gaps']??[]);
$gapIds=[];$openGaps=0;$gapDiffIds=[];
foreach($gaps as $gap){
    $id=(string)($gap['id']??'');
    $require($id!=='' && !isset($gapIds[$id]), 'Duplicate/empty coverage gap: '.$id);
    $require(trim((string)($gap['reason']??''))!=='', 'Gap missing reason: '.$id);
    $require(in_array($gap['status']??'', ['open','accepted'],true), 'Invalid gap status '.$id);
    if (($gap['status']??'')!=='accepted') {$openGaps++;}
    else { $require(!empty($gap['closure_evidence']),
        'Coverage gap accepted without closure evidence: '.$id); }
    if (isset($gap['difference_id'])) { $gapDiffIds[]=$gap['difference_id']; }
    $gapIds[$id]=true;
}
$require(array_diff($unregistered,$gapDiffIds)===[],
    'Unregistered Epic source behaviors lack explicit coverage gaps.');
$unverifiedOperations=0;
foreach($operations as $id=>$op){
    if (($op['disposition']??'')!=='verified'){$unverifiedOperations++;}
    else {
        $accept=(array)($op['acceptance_evidence']??[]);
        foreach(['reachable_source_branches','epic_to_unrealdb_mapping',
                 'independent_differential','all_v5_consumers',
                 'independent_reviewer'] as $field){
            $require(!empty($accept[$field]),
                'Verified original operation lacks '.$field.' evidence: '.$id);
        }
    }
}
$scopeComplete = ($baseline['scope_complete']??false)===true;
$accepted = $scopeComplete && $profileOpen===0 && $unverifiedOperations===0
    && $unverifiedPaths===0 && $openGaps===0 && $openDiffs===[];
$require(($baseline['compliance_claim']??'unverified')!=='accepted'||$accepted,
    'Compliance claimed accepted without complete underlying evidence.');
$require(($baseline['acceptance']['unknown_scope_percentages_allowed']??null)===false,
    'Unknown source scope must not produce compliance percentages.');
if($strict && !$accepted){$errors[]='Strict: source/implementation scope is not accepted.';}
echo json_encode([
    'ok'=>$errors===[], 'compliance_accepted'=>$accepted,
    'scope_denominator_known'=>$scopeComplete,
    'game_profiles'=>count($profiles),'game_profiles_open'=>$profileOpen,
    'registered_operations'=>count($operations),'operations_unverified'=>$unverifiedOperations,
    'ledger_differences'=>count($ledgerIds),'difference_mapping_types'=>$byType,
    'unregistered_source_differences'=>$unregistered,
    'workflow_paths'=>count($paths),'workflow_paths_unverified'=>$unverifiedPaths,
    'coverage_gaps_open'=>$openGaps,'original_operation_priority'=>$priority,
    'issues'=>$errors,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:2);
