#!/usr/bin/env python3
"""T065 original C98/UT99 11 source version gate ownership, no native rerun.
Explicit 1998 v34 unknown object tag vs PHP v35-only compatibility
mismatch remains UNPROVEN as original runtime; do not silently pass.
"""
from pathlib import Path
import json,hashlib,collections,re,sys
ROOT=Path(__file__).resolve().parents[2]
base=ROOT/'catalog/compliance/evidence/o06'
inventory=json.loads((base/'20261031-G3-T065-multi-source-version-gates-still-blocked.json').read_text(encoding='utf-8'))
assert inventory['fixed_id']=='T065' and inventory['status']=='BLOCKED'
source=inventory['source_guards']
checked=0;fail=[];audit=[]
def ck(ok,msg):
 global checked
 checked+=1
 if not ok:fail.append(msg)
cpp_sources={}
for a in source:
 p=Path(a['source_path']);src=p.read_bytes()
 ck(hashlib.sha256(src).hexdigest()==a['SHA256'],'C98/UT99 immutable original C++ source SHA '+a['profile'])
 lines=src.decode('latin1').splitlines()
 ck(lines[a['line']-1].strip()==a['source_gate'],'exact source-selected C++ version guard text '+a['profile']+' '+str(a['line']))
 cpp_sources[a['profile']]=a['SHA256']
phpPaths=[
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php',
 'catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php'
]
php={f:(ROOT/f).read_text(encoding='utf-8') for f in phpPaths}
phphash={f:hashlib.sha256((ROOT/f).read_bytes()).hexdigest() for f in phpPaths}
t=php[phpPaths[0]]
cm=php[phpPaths[1]]
pn=php[phpPaths[2]]
pkg=php[phpPaths[5]]
checks={
 'T99_UClass_legacy_record_v<=61':"$record['old_class_record_size']=$version<=61?$i32():null;" in cm,
 'T99_UClass_within_from_v62':"if($version>=62){" in cm and "$record['class_within_reference']=$reference('ClassWithin')" in cm,
 'T99_UClass_default_within_v<=61':"?'UObject::StaticClass':null" in cm,
 'T99_UFunction_old_three_saved_v<=63':"legacy_parms_size" in php[phpPaths[4]] and "legacy_return_value_offset" in php[phpPaths[4]],
 'T99_UProperty_old_net_flags_v<=61':"$ut99LegacyEffectiveFlags = $version<=61" in pn,
 'T99_FNameEntry_null_terminated_v<64':"$version < 64" in pkg,
 'T99_PackageHeritage_v<68':"if ($version < 68)" in pkg,
 'C98_UClass_tail_two_FName_v<57':"if($version<57 && $family===CatalogUE1ClassicSourceFamily::EPIC_UE1)" in cm,
 'C98_original_unknown_ObjectProperty_archive_v<36':
   'elseif($version===35 && ($type===5||$type===8) && $tagSize===4)' in t
}
for key,present in checks.items():ck(present,'current reader source audit anchor '+key)
original=Path(r'L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnClass.cpp').read_text(encoding='latin1')
ck('if( Ar.Ver()<36 && Tag.Type==NAME_ObjectProperty )' in original,'original C98 source <36 unknown object branch applies to v34 and v35')
ck('elseif($version===35 && ($type===5||$type===8) && $tagSize===4)' in t,
   'CURRENT reader v35-only historical compatibility is NOT a complete original <36 source gate')
ck('elseif($version<36 &&' not in t,'DO NOT misreport v34 original unknown object branch as handled by current reader')
boundary={
 'original_cpp_v34_v35_unknown_object_branch':'Ar.Ver()<36 and Tag.Type==NAME_ObjectProperty on UNKNOWN/INVALID property only; original C98 source',
 'actual_current_ue1_tagged_reader':'version===35 and type5|8 tagSize4, irrespective of explicit source unknown/invalid property',
 'finding':'POTENTIAL_SOURCE_PARITY_DEFECT: version34 physical compact archive unknown-property branch not source-mapped, and classproperty type8 assumption beyond C98 condition. Needs real stock C98 source-compatible v34 fixture and archive-cursor native proof before changing byte reader.',
 'native_original_v34_loader_accepted':False,'native_original_v35_loader_accepted_for_this_branch':False,
 'original_compiled_stock_v432_v60_64_UFunction_source_gate_ALREADY_PASS_T011':True,
 'T065_status':'BLOCKED'
}
n=json.loads((base/'20261031-G3-T011-original-four-loaded-v60-v63-80pass.json').read_text(encoding='utf-8-sig'))
ck(n['ok'] is True and n['checks']==80 and n['source_matched_native_legacy_versions']==[60,61,62,63],
   'previous UT99 genuine compiled v60-v63 function native source gate already accepted NO rerun')
out={'ok':not fail,'read_only_source_gate_checks':checked,'errors':fail,
 'original_source_C98_and_T99_exact_guard_sites':len(source),
 'source_guard_anchor_check_results':checks,'original_5_current_PHP_reader_SHA256':phphash,
 'original_C98_T99_source_SHA256':cpp_sources,
 'unproven_old_C98_version34_branch':boundary,
 'no_original_native_tests_rerun':True,
 'full_T065_ACCEPTED':False,
 'other_missing_T065_native_cursors':'C98 v34/v35 ObjectProperty unknown skip, C98 UClass <57 FNames, UT99 UClass/property <=61 before/after, C227i/k distinct build-selected native CDO/method branch cursors'}
dest=base/'20261031-G3-T065-original-11-C98-UT99-source-gates-v34-gap.json'
dest.write_text(json.dumps(out,indent=2)+'\n',encoding='utf-8')
print(json.dumps({k:v for k,v in out.items() if k not in ('original_5_current_PHP_reader_SHA256','original_C98_T99_source_SHA256','source_guard_anchor_check_results')},indent=2))
sys.exit(0 if not fail else 1)
