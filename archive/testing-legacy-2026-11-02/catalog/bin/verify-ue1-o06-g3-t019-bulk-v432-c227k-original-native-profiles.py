#!/usr/bin/env python3
"""T019 grouped original stock v432 and original C227k load evidence.
Partial native evidence only, NEVER claims the unresolved C98/v432 locale
fallback as complete, and NEVER launches native compiled Unreal engines.
"""
from pathlib import Path
import hashlib,json,re,subprocess,sys
R=Path(__file__).resolve().parents[2]
E=R/'catalog/compliance/evidence/o06'
D=Path(r'C:\Temp\UnrealDB\O06')
pin={
'20261031-G3-T019-v432-isolated-original-compiled-Actor.uc':'0b62b59474dbd54248fcac1e497b774bec16352d27b24fb7f9f447b64b1b6587',
'20261031-G3-T019-v432-isolated-original-compiled-ProbeCommandlet.uc':'cfa9692be3d47d64618c69c219b549822eeb81026bba479fb1e1f9b9524ddb32',
'20261031-G3-T019-original-v432-UCC-make-success.log':'7a0df691938905aca4894155d9fddcc01525829a8021fd41de3b6b62096a4dea',
'20261031-G3-T019-original-v432-config-transient-111.log':'05ebb5993b585691c50ceeeca02f36424e594fa8a0b38b99788466658df78f3e',
'20261031-G3-T019-original-v432-config-transient-222.log':'a46453b265326e86e7d9f9c28841f78f89e04eca27cb470d16ca50f710e8e80f',
'20261031-G3-T019-original-227k-config-localized-111.log':'79c9c2b58679c02c23746fcbe183ad89d83ef02975cb471fce22939c57d6a54a',
'20261031-G3-T019-original-227k-config-localized-222.log':'184333d09df3c244b56d63c9e15902d883a356569cb8e75de0997007956f8fb2',
}
checks=0;fail=[];cases=[]
def ck(b,msg):
 global checks
 checks+=1
 if not b:fail.append(msg)
def txt(file):
 b=file.read_bytes()
 return b[2:].decode('utf-16le','replace') if b[:2]==b'\xff\xfe' else b.decode('utf-8','replace')
for name,sha in pin.items():
 f=E/name
 ck(f.is_file() and hashlib.sha256(f.read_bytes()).hexdigest()==sha,'SHA-pinned original native/source log '+name)
ck(hashlib.sha256((D/'Native-UCC/System/G3BulkConfigV432.u').read_bytes()).hexdigest()=='73e99ca3aeed3f87107da80d6abfb9748056247dc19dc08d1e36dd6098f6bfd8','original v432 UCC actual compiled test package exact SHA not committed')
ck(hashlib.sha256((D/'Native-UCC/System/Core.dll').read_bytes()).hexdigest()=='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8','shipped original v432 Core.dll provenance')
ck(hashlib.sha256((D/'Native-227k/System/Core.dll').read_bytes()).hexdigest()=='c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194','original 2026 227k Core.dll separate family')
make=txt(E/'20261031-G3-T019-original-v432-UCC-make-success.log')
ck('G3BulkConfigV432' in make and 'Success - 0 error(s), 0 warnings' in make,'actual original 432 UCC compiled cleanly')
actor=txt(E/'20261031-G3-T019-v432-isolated-original-compiled-Actor.uc')
for s in ('var config int ConfigValue','var localized string LocalMessage','var transient int ScratchValue','var int PersistValue'):
 ck(s in actor,'original source-backed property type '+s)
for val in (111,222):
 t=txt(E/f'20261031-G3-T019-original-v432-config-transient-{val}.log')
 for sig in ('T019_ORIGINAL_V432_START','T019_ORIGINAL_V432_DONE',
       f'T019_ORIGINAL_V432_CONFIG={val}',
       'T019_ORIGINAL_V432_LOCALIZED=DefaultBulk',
       'T019_ORIGINAL_V432_TRANSIENT=0','T019_ORIGINAL_V432_PERSIST=888'):
  ck(sig in t,f'original v432 native executed correct {val} {sig}')
 ck('Error' not in t,'original v432 UCC source config native commandlet completed without fatal')
 cases.append({'original_compiled_build':'UT99-v432','profile':val,'config_value':val,'localized_runtime':'DefaultBulk','transient_runtime':0,'persist_runtime':888})
for val,lang in ((111,'Native Alpha'),(222,'Native Beta')):
 t=txt(E/f'20261031-G3-T019-original-227k-config-localized-{val}.log')
 ck('G3_B08_227_RELOADED_COUNT 1' in t,'original C227k full native class loaded '+str(val))
 ck('G3_B08_NATIVE_LOCALIZED_BEFORE cdoValue=Default Message' in t,'original C227k localized pre value '+str(val))
 ck('G3_B08_NATIVE_LOCALIZED_AFTER cdoValue='+lang in t,'original C227k localized AFTER explicit native LoadLocalized '+str(val))
 ck('G3_B08_NATIVE_RELOADED name=ConfigValue' in t,'original C227k source config value present '+str(val))
 ck(re.search(r'G3_B08_NATIVE_RELOADED name=ConfigValue.*?value='+str(val),t,re.S) is not None,'actual original C227k changed source config '+str(val))
 ck('value=888' in t,'original C227k Persist native value '+str(val))
 ck('value=0' in t,'original C227k transient was not persisted '+str(val))
 cases.append({'original_compiled_build':'Unreal-C227k','profile':val,'config_value':val,'localized_runtime':lang,'transient_runtime':0,'persist_runtime':888})
ck(len(cases)==4,'two different real original source families and two controlled native config values')
result={'ok':not fail,'checks':checks,'failures':fail,'fixed_T019':'BLOCKED',
 'genuine_original_compiled_source_profiles':['UT99-v432','Unreal-C227k'],
 'original_compiled_native_runs_bounded_new':2,'original_compiled_native_runs_reused_earlier':2,
 'original_native_executions_by_this_verifier':0,
 'verified_v432_config_transient_profile_count':2,'verified_C227k_localized_config_transient_profile_count':2,
 'unresolved_C98_native_controlled_two_profile':True,
 'unresolved_UT99_v432_localized_override_explicit_observation':True,
 'unresolved_C227i_separate_original_native_localization_precedence':True,
 'original_UE1_C98_profile_not_falsely_inferred_from_C227':True,
 'G3_full_acceptance':False,'cases':cases}
print(json.dumps(result,indent=2))
sys.exit(0 if not fail else 1)
