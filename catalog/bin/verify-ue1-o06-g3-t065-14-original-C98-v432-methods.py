#!/usr/bin/env python3
"""Read-only 14 real original engine method cases; no native engine replay."""
from pathlib import Path
import json,hashlib,re,sys,collections
R=Path(__file__).resolve().parents[2]
E=R/'catalog/compliance/evidence/o06'
m=json.loads((E/'20261101-G3-T065-T071-T072-T075-14-C98-v432-original-method-source-boundaries.json').read_text())
errors=[];checks=0
def ck(good,label):
 global checks
 checks+=1
 if not good:errors.append(label)
ck(m['NEW_genuine_original_method_ver_distinct_14_cases']==14
   and m['NEW_original_1998_C98_method_cases']==8
   and m['NEW_original_v432_native_method_cases']==6,'14 disjoint actual original method cases')
ck(m['original_stock_Core_binaries_untouched'] and m['original_PRD_database_query_readonly'],
   'original stock engine files and PRD untouched')
texts={}
for p in m['original_stdout_and_oracle_source_SHA_pins']:
 f=R/p['path']
 ck(f.is_file() and f.stat().st_size==p['bytes']
    and hashlib.sha256(f.read_bytes()).hexdigest()==p['sha256'],
    'original native observed data/source compiler SHA '+p['path'])
 texts[f.name]=f.read_bytes().decode('latin1',errors='replace')
stock=Path(r'C:\Temp\UnrealDB\O06')
for family,folder,sha in (
 ('original C98 1998',stock/'Native-C98-1998-G304/System','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'),
 ('original UT99 v432',stock/'Native-UCC/System','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8')):
 ck(hashlib.sha256((folder/'Core.dll').read_bytes()).hexdigest()==sha,
    family+' canonical source compiled Core binary SHA')
c98=next(v for k,v in texts.items() if 'C98-1998-v55-v58-real-UClass-methods.log' in k)
nativeclass=next(v for k,v in texts.items() if 'original-stock-v432-UClass-v61-v62-four-native-methods.log' in k)
prop=next(v for k,v in texts.items() if 'original-stock-v432-UProperty-v61-v62-two-native-methods.log' in k)
for n in (0,1):
 ck(f'G3_C98_UCLASS_POINTER CLASS={n} PTR=' in c98,'original C98 actual intrinsic class owner pointer '+str(n))
 for ver in (55,56,57):
  ck(f'G3_C98_NATIVE_UCLASS_EXCEPTION CLASS={n} VER={ver}' in c98,
     'actual C98 original expected missing linker assertion v'+str(ver)+' class'+str(n))
 ck(f'G3_C98_NATIVE_UCLASS CLASS={n} VER=58 NAMES=2 REFS=8 RAW=66 CURSOR=76' in c98,
     'C98 original v58 direct UClass method completed '+str(n))
 for ver,names,refs,raw,calls in ((61,2,7,66,15),(62,3,8,62,14)):
  ck(f'G3_T065_ORIGINAL_V432_UClass_VER={ver} CLASS={n} NAMES={names} REFS={refs} BYTES={raw} CALLS={calls}' in nativeclass,
     'original v432 stock UClass compiled archive branch '+str(n)+' '+str(ver))
ck('G3_T065_ORIGINAL_V432_UClass_ORACLE_OWNER=0 PTR=' in nativeclass
   and 'G3_T065_ORIGINAL_V432_UClass_ORACLE_OWNER=1 PTR=' in nativeclass,
   'original v432 real intrinsic UClass pointer identities for both class types')
ck('G3_T065_ORIGINAL_V432_Engine_LOADED_PTR=0146A090 ACTOR_PTR=10589798' in prop,
   'original v432 actual Engine.u script Actor UClass loaded')
ck('G3_T065_ORIGINAL_V432_UProperty_OWNER_PTR=033CCF48' in prop,
   'original v432 actual loaded Actor reflected UProperty pointer')
for ver,flags in ((61,'00000003'),(62,'00080043')):
 ck(f'G3_T065_ORIGINAL_V432_UProperty_VER={ver} BEFORE=00080043 AFTER={flags} NAMES=2 REFS=5 BYTES=12 CALLS=3' in prop,
    'compiled v432 actual original UProperty net flag clearing branch v'+str(ver))
ck('G3_T065_ORIGINAL_V432_6_NATIVE_ORACLES=2' in prop,
   'property-only command skipped previously accepted class cases')
orig_c98=Path(r'L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnObj.cpp').read_text('latin1')
ck('check(Linker);' in orig_c98 and 'if( Ar.Ver() < 58 )' in orig_c98,
   'original C98 source condition and actual intrinsic without linker assertion')
orig_t99=Path(r'L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src')
cls=(orig_t99/'UnClass.cpp').read_text('latin1')
upr=(orig_t99/'UnProp.cpp').read_text('latin1')
ck('if( Ar.Ver() <= 61 )//oldver' in cls and 'if( Ar.Ver()>=62 )' in cls,
   'original UT99 original source 61 ClassRecordSize and v62 ClassWithin gate')
ck('PropertyFlags &= ~0x00080040;' in upr and 'if( Ar.Ver() <= 61 )//oldver: clear old net flags.' in upr,
   'original UT99 UProperty <=61 real flag mask source gate')
ph=(R/'catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php').read_text('utf-8')
pr=(R/'catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php').read_text('utf-8')
ck("$record['old_class_record_size']=$version<=61?$i32():null;" in ph
   and "if($version>=62){" in ph,
   'current source-selected reader has actual original v61/62 UClass branch')
ck('$ut99LegacyEffectiveFlags = $version<=61' in pr and '0x00080040' in pr,
   'current source-selected reader has actual original UProperty <=61 flags transform')
inventory=json.loads(next(E.glob('20261101-G3-T065-T071-T072-T075-PRD-readonly-real-v34-58-corpus-availability.json')).read_text())
ck(inventory['readonly'] is True and inventory['source_version_game_counts']==[]
   and inventory['first_45_version_candidates']==[],
   'no verified original version34,35,36,55-58 package currently available in indexed PRD')
ck(m['scope_limit'].startswith('Original test archive FArchive is controlled'),
   'do not claim full linked retail package runtime loaded-value source gate')
o={'ok':not errors,'checks':checks,'errors':errors,'new_original_stock_compiled_method_cases':14,
 'actual_positive_compiled_method_cases':8,'original_compiled_intrinsic_unlinked_expected_assertion_cases':6,
 'original_compiled_engine_profiles':['C98 1998','UT99 v432'],
 'source_version_gates_new_verified':['C98 UObject::Serialize v<58 link requirement',
 'UT99 v432 UClass::Serialize 61 old ClassRecordSize versus 62 ClassWithin/Config',
 'UT99 v432 UProperty::Serialize <=61 PropertyFlags 0x00080040 clear versus 62 preserve'],
 'original_native_tests_launched_by_this_regression':0,
 'complete_frozen_T065_T071_T072_T075_T077':'STILL BLOCKED: other source families/methods and genuine source physical archive cursors'}
(E/'20261101-G3-T065-T071-T072-T075-14-original-method-source-branch-verification.json').write_text(json.dumps(o,indent=2)+'\n')
print(json.dumps(o,indent=2))
sys.exit(0 if not errors else 1)
