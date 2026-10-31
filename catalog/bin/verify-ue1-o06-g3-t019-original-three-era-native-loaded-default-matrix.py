#!/usr/bin/env python3
"""T019: 1998 C98 + stock UT99 v432 + 2012 C227i + 2026 C227k.
One original compiled acceptance decision. No native engines run here.
Target-specific original C227i CDO + editor-state observed, without asserting C227i gameplay noneditor.
"""
from pathlib import Path
import hashlib,json,re,sys
ROOT=Path(__file__).resolve().parents[2]
EV=ROOT/'catalog/compliance/evidence/o06'
P=EV/'20261031-G3-T019-original-C98-v432-C227i-C227k-controlled-native-matrix.json'
m=json.loads(P.read_text(encoding='utf-8'))
checks=0;errors=[]
def ck(ok,msg):
 global checks
 checks+=1
 if not ok:errors.append(msg)
def digest(p):
 return hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else None
def sealed(name):
 return EV/f'20261031-G3-T019-original-native-{name}'
ck(m['test']=='T019' and m['T019_frozen_status']=='PASS','original 2012 target-specific editor-mode proof completes frozen T019' )
ck(m['2012_original_C227i_runtime_editor_flag_at_method_entry']==0,'real C227i original editor state was ALREADY zero at the method breakpoint')
ck(m['2012_original_C227i_exact_target_object_method_entry_not_attributed'] is False,'target original C227i method identity now captured')
for item in m['SHA_verified_original_native_and_user_authored_observer_outputs']:
 p=ROOT/item['path']
 ck(digest(p)==item['sha256'],'SHA original native/or authored file '+p.name)
 ck(p.is_file() and p.stat().st_size==item['bytes'],'size original native/or authored file '+p.name)
for key,data in m['compiled_original_binaries'].items():
 p=Path(data['private_DEV_only_file'])
 ck(digest(p)==data['sha256'],'original private source-family binary/class archive SHA '+key)
 ck(p.is_file() and p.stat().st_size==data['bytes'],'original private bin size '+key)
c98=Path(r'L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnObj.cpp')
ck(digest(c98)=='560d2ab53d5094b44e1b7f3d56db0b958d63c64ddceebaa0531f6e30f0577462','1998 Epic original source pin')
source=c98.read_text(encoding='latin1')
ck(re.search(r'void UObject::LoadConfig.*?if\(\s*Type==NAME_Localized\s*\)\s*return\s*;',source,re.S) is not None,'C98 original source explicitly NO-OPS localized config')
for v,hexcode in ((905,'00000389'),(906,'0000038a')):
 p=sealed(f'C98-1998-{v}-native.log');t=p.read_text(encoding='latin1')
 ck(f'G3_T019_C98_NATIVE_540BYTE_CLASS_{v}' in t,'native original 1998 C98 correct 540-byte class load '+str(v))
 ck('0000021c 0000021c' in t,'actual original C98 Defaults size matches selected CDO')
 ck('00000315' in t,'original native C98 nested struct BX 789')
 ck(hexcode in t,'original native C98 INI override physically in class default buffer '+str(v))
 ck('61666544 42746c75 006b6c75' in t,'actual original C98 CDO contains default localization string')
 ck(not re.search(r'(?m)^[0-9a-f]{8}\s+0000014d\b',t,re.I),'transient input 333 NOT loaded in C98 class defaults')
 ck(not re.search(r'(?im)syntax error|unable to resolve symbol|access violation',t),'no original engine debugger critical')
v432=Path(r'L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnObj.cpp')
ck(digest(v432)=='4dd6a4de7b5584015aa5ac79501cca4baca5315df4c65f6b0ae746ab9e1de3b2','UT99 inherited v400 original localized source pin')
ck('if( GIsEditor )' in v432.read_text(encoding='latin1'),'native editor-skip source guard')
for n,lang in ((555,'Native Eta'),(666,'Native Theta')):
 t=sealed(f'UT99-v432-{n}-native.log').read_text(encoding='latin1')
 for marker in [f'T019_V432_ORIGINAL_CPP_INT ConfigValue={n}',f'T019_V432_ORIGINAL_CPP_LOCAL_AFTER={lang}',
    'T019_V432_ORIGINAL_CPP_LOCAL_BEFORE=DefaultBulk',
    'T019_V432_ORIGINAL_CPP_INT ScratchValue=0',
    'T019_V432_ORIGINAL_CPP_INT PersistValue=888','T019_V432_ORIGINAL_CPP_RESULT=1']:
  ck(marker in t,'original stock v432 actual CDO value '+str(n)+' '+marker)
ck('Default->LoadLocalized(0,C)' in sealed('UT99-v432-original-CPP-observer.cpp').read_text(encoding='utf-8'),'source-backed native direct noneditor LoadLocalized observer')
for n in (777,888):
 t=sealed(f'C227i-2012-{n}-native.log').read_text(encoding='latin1')
 uc=sealed(f'C227i-2012-{n}-exported-default.uc').read_text(encoding='latin1')
 ck(f'Exported Class G3Config227.G3Config227Actor' in t,'original native 2012 real class actually exported '+str(n))
 ck('0 warning(s)' in t,'genuine original 2012 UCC no native warning '+str(n))
 ck(re.search(rf'(?m)^\s*ConfigValue={n}\s*$',uc) is not None,'2012 C227i config actually loaded override '+str(n))
 ck(re.search(r'(?m)^\s*LocalMessage="Default Message"\s*$',uc) is not None,'2012 compiled editor CDO retains source default localized field')
 ck(re.search(r'(?m)^\s*PersistValue=222\s*$',uc) is not None,'2012 original compiled persistent field')
 ck(re.search(r'(?m)^\s*ScratchValue=',uc) is None,'2012 native transient not exported as changed stored default')
for n in (999,1000):
 log=(EV/f'20261031-G3-T019-original-C227i-{n}-native-loaded-debugger.log').read_text(encoding='latin1')
 uc=(EV/f'20261031-G3-T019-original-C227i-{n}-native-original-class-export.uc').read_text(encoding='latin1')
 ck('C227i_ORIGINAL_LOADLOCALIZED_ENTRY' in log,'original C227i compiled LoadLocalized real native entry reached '+str(n))
 ck(log.count('101d0dd0  00000000')==2,'C227i original GIsEditor already zero on entry, debugger edited no DIFFERENT editor flag')
 ck(re.search(rf'(?m)^\s*ConfigValue={n}\s*$',uc) is not None,'same original C227i archived class had controlled source config '+str(n))
 ck('LocalMessage="Default Message"' in uc,'C227i exporter retains default after native function entry, not target-specific value')
 ck('Native Lambda' not in uc and 'Native Mu' not in uc,'cannot infer C227i native localized override from original source package alone')
target=m['2012_original_C227i_native_target_specific_Localized_observed']
ck(target['profile']==1003 and target['exact_target_native_CDO_ConfigValue']==1003,'2012 real original class target config and CDO instance identity')
ck(target['original_native_CDO_before_Localized']=='Default Message','2012 actual CDO FString loaded native memory before editor skip')
ck(target['native_editor_flag_at_exact_source_method_entry']==1,'C227i method exact target GIsEditor=1')
ck(target['compiled_native_stack_owner']=='Core!UClass::Serialize+0x21a','source binary original 2012 class serializer calls native localized method')
ck(target['original_native_UCC_exported_after_Localized']=='Default Message','2012 original source-native editor localization skipped, persisted default')
ck(target['2012_noneditor_same_method_not_asserted'].startswith('T074'),'C227i 2012 noneditor gameplay method still separately owned and not falsely asserted')
txt=(EV/'20261031-G3-T019-original-C227i-2012-target-1003-native.log').read_text(encoding='latin1')
ck(txt.count('T019_C227I_NATIVE_TARGET_CDO_CONFIG_LOCALIZED')==2,'source debugger + exactly one genuine original target CDO method breakpoint')
ck('000003eb' in txt and '026d9ad8  "Default Message"' in txt,'original 2012 target exact native offset 0x28c and FString loaded')
ck('101d0dd0  00000001' in txt,'original compiled native C227i editor-mode condition true at target')
ck('Core!UClass::Serialize+0x21a' in txt,'original 2012 native stack truly class serialization, not unrelated localized call')
uc=(EV/'20261031-G3-T019-original-C227i-2012-target-1003-class-default.uc').read_text(encoding='latin1')
ck('ConfigValue=1003' in uc and 'LocalMessage="Default Message"' in uc and 'PersistValue=222' in uc and 'ScratchValue=' not in uc,'C227i original compiled native export after actual CDO editor localization skip')
prev=json.loads((EV/'20261031-G3-T019-four-original-native-config-window-44pass-BLOCKED.json').read_text(encoding='utf-8-sig'))
ck(prev['ok'] is True and prev['checks']==44 and prev['fixed_T019']=='BLOCKED','previously accepted compiled C227k and v432 original native evidence reused NO reexecution')
for n,s in ((111,'Native Alpha'),(222,'Native Beta')):
 p=EV/f'20261031-G3-T019-original-227k-config-localized-{n}.log'
 ck(digest(p)==('79c9c2b58679c02c23746fcbe183ad89d83ef02975cb471fce22939c57d6a54a' if n==111
                else '184333d09df3c244b56d63c9e15902d883a356569cb8e75de0997007956f8fb2'),
    'previous original C227k source-build native loaded report immutable '+str(n))
 raw=p.read_bytes()
 native_text=raw[2:].decode('utf-16le','replace') if raw.startswith(b'\xff\xfe') else raw.decode('utf-8-sig','replace')
 ck(f'G3_B08_NATIVE_LOCALIZED_AFTER cdoValue={s}' in native_text,
    '2026 original native explicit CDO Localize loaded value '+str(n))
ck(len(m['original_compiled_C98_1998_two_controlled_class_default_buffers'])==2 and
   len(m['original_compiled_UT99_v432_two_actual_real_CDO_loads'])==2 and
   len(m['original_compiled_C227i_2012_two_actual_native_editor_CDO_exports'])==2,
   'source family evidence complete to the exact observed branching scope')
result={'ok':not errors,'checks':checks,'errors':errors,
 'T019_fixed_status':'PASS',
 'true_original_native_C98_1998_controlled_profiles':[905,906],
 'true_original_native_UT99_v432_actual_cpp_CDO_Localized_profiles':[555,666],
 'true_original_native_C227i_2012_editor_profiles':[777,888],
 'true_original_native_C227i_2012_compiled_LoadLocalized_entry_observations':[999,1000],
 'true_original_native_C227k_2026_explicit_CDO_loaded_localized_profiles_REUSED':[111,222],
  'complete_source_specific_2012_C227i_editor_mode_localized_exclusion_proven':True,
 'different_2012_C227i_noneditor_gameplay_localization_method_claimed':False,
  'source_selected_2012_C227i_original_CDO_native_observation':'Original compiled Core!UClass::Serialize calls Core!UObject::LoadLocalized for exact target CDO ConfigValue1003, LocalMessage Default Message, GIsEditor=1 triggers editor-mode skip; matching UCC exported default. Separate 2026 compiled noneditor actual localized values reused. Do not equate build families.',
 'original_native_engine_executions_by_verifier':0,'G3_full_acceptance':False}
print(json.dumps(result,indent=2,ensure_ascii=False))
sys.exit(0 if not errors else 1)
