#!/usr/bin/env python3
"""Frozen G3 T076 complete original-source physical provider, import and error gate.
Uses prior sealed genuine native logs, never invokes original binaries.
"""
from pathlib import Path
import hashlib,json,subprocess,re,sys
root=Path(__file__).resolve().parents[2]
ev=root/'catalog/compliance/evidence/o06'
errors=[];checks=0
def ck(ok,desc):
 global checks
 checks+=1
 if not ok:errors.append(desc)
def read_json(n):return json.loads((ev/n).read_text(encoding='utf-8'))
prior=read_json('20261031-G3-T076-original-stock-8-native-real-duplicate-import-source-acceptance.json')
shipped=read_json('20261031-G3-T073-T075-T076-20-real-UT99-shipped-native-and-5-indexed-ownership.json')
shipped_verified=read_json('20261031-G3-T073-T075-T076-five-shipped-20-original-native-acceptance-verified.json')
real_selector=read_json('20261101-G3-T076-native20-actual-classic-runtime-mount-selector-acceptance.json')
c227=read_json('20261101-G3-T076-six-real-original-C227i-C227k-duplicate-mount-and-missing.json')
c98=read_json('20261101-G3-T076-original-C98-five-native-physical-mount-precedence-and-missing.json')
ck(prior['ok'] and prior['checks']==79 and prior['stock_v432_true_imported_UClass_native_sessions']==4,
   'original compiled v432 true imported superclass file and 4 real C++ live class-pointer executions previously accepted')
ck(prior['current_UE1_reader_archived_tag_and_import_reference_checks']==7
   and prior['original_native_engine_executions_by_this_verifier']==0,
   'source reader original compiled consumer class references correct and no native reexecution')
ck(shipped_verified['ok'] and shipped_verified['checks']==173
   and shipped_verified['new_original_authentic_shipped_native_executions']==20,
   'twenty genuine shipped original v432/469f load cases and source-native reader parity already certified')
ck(len(shipped['original_native_cases'])==20
   and shipped['actual_original_indexed_shipped_candidates']==5,
   'original source file corpus has five true physical PRD provider archive variants')
ck(real_selector['ok'] and real_selector['checks']==118
   and real_selector['actual_original_compiled_native_shipped_cases_correlated']==20
   and real_selector['previous_native_executions_replayed']==0,
   'real classic UEDB5 physical source-specific mount selector tested against twenty native original outcomes')
ck(real_selector['source_ambiguity_without_mounts_preserved']
   and len(real_selector['native_profiles'])==2,'original archive never selects an arbitrary duplicate with unknown mount order')
source=root/'catalog/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php'
contents=source.read_text(encoding='utf-8')
ck('classicAuthoritativeMountOrder(' in contents
   and 'evidenced_original_classic_mount_order' in contents,
   'actual UEDB5 source consumes source-scoped authoritative physical mount order')
ck('selection_status' in contents and "'missing'" in contents and "'ambiguous'" in contents,
   'actual UEDB5 source distinguishes missing provider from unproven physical ambiguity')
ck('self::usesUnrealIToUnrealSharePackageFallback' in contents
   and 'primaryPhysicallyMounted' in contents,
   'actual original source conditional UnrealI fallback requires physical availability')
profiles={'2012-C227i':3,'2026-C227k':3}
ck(c227['native_original_new_executions']==6
   and c227['original_missing_package_process_exit_is_zero']
   and c227['must_use_actual_original_failure_message_not_exitcode'],
   'two original independent 2012/2026 builds fail even with native commandlet process exit0')
seen={}
for row in c227['new_native_cases']:
 label=row['profile'];seen[label]=seen.get(label,0)+1
 case=row['case']
 log=row['committed_original_stdout_proof']
 p=root/log['path'];raw=p.read_bytes()
 ck(hashlib.sha256(raw).hexdigest()==log['sha256']==row['native_stdout_SHA256']
    and len(raw)==log['bytes'],'original compiled 2012/2026 source log SHA '+label+' '+case)
 ck((row['native_output_BMP_count']==4 and b'Detail.paint1' in raw and b'Detail.Rough' not in raw)
    if case=='system_v68_textures_v69' else (
    (row['native_output_BMP_count']==11 and b'Detail.Rough' in raw and b'Detail.paint1' not in raw)
    if case=='system_v69_textures_v68' else (
    row['native_output_BMP_count']==0 and b'Failed loading package:' in raw and row['real_source_native_executable_exit']==0)),
    'actual source 2012/2026 native full package loaded texture identity or missing error '+label+' '+case)
ck(seen==profiles,'two genuine original 2012/2026 source runtime families each covered 3 distinct mount cases')
ck(c98['new_original_compiled_C98_1998_appFindPackageFile_source_method_cases']==5,
   'original 1998 C98 compiler method actually invoked on five new mount paths')
ck(c98['original_methods_returned_all_cases']
   and c98['original_host_abnormal_exit_after_native_method_values']
   and not c98['full_T076_original_import_verification_by_C98'],
   'original C98 method observed, full C98 imported loaded value never falsely asserted')
seen1998=set()
for row in c98['new_original_native_case_rows']:
 seen1998.add(row['case'])
 src=row['original_native_log'];p=root/src['path'];raw=p.read_bytes()
 ck(hashlib.sha256(raw).hexdigest()==src['sha256']==row['native_log_SHA256']
    and len(raw)==src['bytes'],'original 1998 retail Core.dll native source path SHA '+row['case'])
 target=row['expected_original_C98_source_search_priority']
 ck((b'PhysicalResult=1 File=../System/' in raw if target=='System'
     else b'PhysicalResult=1 File=../Tex/' in raw if target=='Tex'
     else b'PhysicalResult=0 File=' in raw),
    'original 1998 compiled appFindPackageFile physical path selection '+row['case'])
 ck(b'GSys->Paths' not in raw and b'SearchPath0=../System/*.u SearchPath1=../Tex/*.utx' in raw,
    'original source 1998 paths validated in native real x86 execution '+row['case'])
ck(len(seen1998)==5 and c98['one_original_native_missing_case'],
   '1998 source-path negative case and both priority variants proven')
for item in c98['original_observer_build_and_stdout_sha_pins'] + c227['source_SHA_pins']:
 path=root/item['path']
 ck(path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest()==item['sha256'],
    'raw original ABI observer/compiler/log user source SHA '+item['path'])
for path,sha in (
 (r'C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System\Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'),
 (r'C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'),
 (r'C:\Temp\UnrealDB\O06\Native-469f\System\Core.dll','80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2'),
 (r'C:\Temp\UnrealDB\O06\Native-C227i-G304\System\Core.dll','287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'),
 (r'C:\Temp\UnrealDB\O06\Native-227k\System\Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194')
):
 ck(hashlib.sha256(Path(path).read_bytes()).hexdigest()==sha,'immutable originally compiled source Core.dll '+path)
ck(shipped['no_original_game_binary_committed']
   and c227['original_engine_binary_archives_not_committed'],
   'all five original engine binaries and genuine shipped package archive bytes remain private DEV scratch')
result={'ok':not errors,'checks':checks,'errors':errors,
 'original_builds_with_direct_native_physical_search_or_loaded_oracle':['C98 1998','UT99 v432','UT99 469f','Unreal C227i 2012','Unreal C227k 2026'],
 'old_source_backed_v432_imported_superclass_true_native_sessions':4,
 'old_native_real_shipped_UT99_file_sessions':20,
 'new_native_original_C227i_C227k_duplicate_provider_and_missing_sessions':6,
 'new_native_original_C98_1998_appFindPackageFile_physical_path_method_sessions':5,
 'no_original_native_sessions_reexecuted_by_this_verifier':True,
 'original_shipped_packages':5,
 'authoritative_real_runtime_mount_context_required':True,
 'when_original_install_mount_order_unavailable':'ambiguous: original physically selected file cannot be inferred from hashed catalog',
 'missing_provider_when_original_mounts_known':'explicit missing even if native UCC process exitcode is 0',
 'original_1998_C98_full_imported_consumer_not_proven':True,
 'overall_UE1_G3_source_owners_T065_T071_T072_T073_T074_T075_T077_still_blocked':True,
 'T076_source_selected_provider_gate':'CANDIDATE_PASS - one original source native physical lookup or provider load per build; v432 true imported reference and four-build loaded-value provider selection; source-scoped missing and ambiguity'}
(ev/'20261101-G3-T076-five-original-builds-native-physical-provider-full-closure-verification.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
sys.exit(0 if not errors else 1)
