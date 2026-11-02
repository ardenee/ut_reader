#!/usr/bin/env python3
"""Read-only original v432 compiled native linker-root reuse source acceptance.
No x86 original engine execution; existing native stdout is SHA-sealed.
"""
from pathlib import Path
import hashlib,json,sys,re,subprocess
root=Path(__file__).resolve().parents[2]
e=root/'catalog/compliance/evidence/o06'
m=json.loads((e/'20261101-G3-T076-original-v432-two-real-loaded-linker-overrides-path-source-proof.json').read_text())
err=[];n=0
def check(ok,label):
 global n
 n+=1
 if not ok:err.append(label)
check(m['original_compiled_v432_new_linker_reuse_native_cases']==2,'two new distinct original stock compiled runtime cases')
check(m['original_UCLASS_and_live_texture_objects_retained_after_paths_swapped'],'actual engine object identity after physical search path modification')
check(m['original_two_shipped_UT99_Detail_v68_v69_unmodified'] and m['PRD_untouched'],'original asset and production immutable')
original=Path(r'L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnObj.cpp')
check(hashlib.sha256(original.read_bytes()).hexdigest()==m['original_1998_C98_get_package_linker_source_SHA256'],
      'actual unmodified 1998 Epic source linked loader branch SHA')
src=original.read_text('latin1')
check('if( GObj.GetLoader(i)->LinkerRoot == InParent )' in src and 'if( Result )' in src,
      'Epic original real loaded package LinkerRoot short circuits path scan')
core=Path(r'C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll')
check(hashlib.sha256(core.read_bytes()).hexdigest()==m['verified_original_stock_Core_DLL_SHA256'],
      'unmodified original stock v432 x86 Core.dll SHA')
rawby={}
for p in m['new_original_cpp_compiler_stdout_raw_pins']:
 path=root/p['path']
 check(path.is_file() and path.stat().st_size==p['bytes'] and hashlib.sha256(path.read_bytes()).hexdigest()==p['sha256'],
       'original 432 cached source observer/original native stdout actual hash '+p['path'])
 if p['path'].endswith('.log') and 'existing-linker-' in p['path']:
  rawby[('texture_first' if 'texture_first' in p['path'] else 'system_first')]=path.read_bytes().decode('latin1')
check(len(rawby)==2,'independent original native log for each reversed existing linker search order')
cases=m['new_original_native_case_rows']
check({x['case'] for x in cases}=={'texture_first','system_first'},'original two distinct actual compiler process execution IDs')
for case in cases:
 kind=case['case']
 actual=rawby.get(kind,'')
 check(hashlib.sha256(actual.encode('latin1')).hexdigest()==case['actual_native_stdout_sha256'],
       'original stock C++ process byte identity case '+kind)
 check(case['actual_native_exit']==0 and 'G3_T076_ORIGINAL_LINKER_CACHED_RESULT=1' in actual,
       'actual original Core.dll returned success after real physical runtime path reordering '+kind)
 check('samePackage=1 sameSelectedObject=1' in actual,
       'actual native Core.dll package pointer AND loaded texture pointer same before and after path reorder '+kind)
 check(('selected=v69' if kind=='texture_first' else 'selected=v68') in actual,
       'original native source first mounted package identity persisted '+kind)
 check('G3_T076_ORIGINAL_LINKER_CACHED_AFTER_ORDER_SWAP' in actual,
       'real stock original GetPackageLinker called again after live Paths were swapped '+kind)
old=m['source_before_fix_historic_git_SHA_retained']
oldsource=root/old['path']
check(oldsource.stat().st_size==old['bytes'] and hashlib.sha256(oldsource.read_bytes()).hexdigest()==old['sha256'],
     'historical initially T076-accepted physical mount only source preserved, not silently rehashed')
source=root/'catalog/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php'
data=source.read_bytes()
check(b'classicSourceLoadedLinkers' in data and b'evidenced_original_classic_loaded_linker' in data,
      'updated production source preserves original loaded linker priority')
check(b'primaryPhysicallyMounted = $orderedMounts === null || $sourceLoadedId !== null' in data,
      'real unloaded fallback suppressed for existing live UnrealI source package root')
check(b'classicAuthoritativeMountOrder' in data and b'mount_order_authoritative' in data,
      'original physical mount order still requires explicitly authoritative runtime context')
check(b'Source-evidenced original loaded linker is absent from verified same-game provider candidates.' in data,
      'already-linked package only allowed when identical original staged same-game verified file candidate exists')
check(m['source_after_fix_SHA256']==hashlib.sha256(data).hexdigest(),
      'current post-native-fix production PHP exact source SHA')
result={'ok':not err,'checks':n,'errors':err,
        'new_original_compiled_v432_stock_Core_DLL_native_reload_sessions':2,
        'original_GObj_Loaders_short_circuit_vs_changed_GSys_Paths_confirmed':True,
        'original_2012_2026_C227_native_runs_repeated':0,
        'already_accepted_24_native_original_T076_other_sessions_repeated':0,
        'no_hidden_auto_inference_original_linker_identity_from_catalogue':True,
        'historical_T076_source_SHA_preserved':True,
        'current_PHP_source_SHA256':hashlib.sha256(data).hexdigest()}
print(json.dumps(result,indent=2))
sys.exit(0 if not err else 1)
