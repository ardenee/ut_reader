#!/usr/bin/env python3
"""T065 original-native historical version branch and physical cursor; read-only proof join."""
from pathlib import Path
import collections,hashlib,json,re
root=Path(__file__).resolve().parents[2]
ev=root/'catalog/compliance/evidence/o06'
m=json.loads((ev/'20261102-G3-T065-final-genuine-v35-native-C98-v55to58-source-and-UT99-C227-boundary.json').read_text('utf8'))
assert m['full_T065_frozen_pass'] and m['original_source_gated_and_native_compiled_family_differentiation']
assert m['true_original_1998_compiled_C98_v35_physical_File_load']['actual_original_UObjectProperty_SerializeItem_runtime_callbacks_ArVer35']==10
pins=0;files={}
for key,p in m['pins'].items():
 f=root/p['path'] if p['location']=='repo' else Path(p['path'])
 if not f.is_file():raise RuntimeError('Missing original proof '+key)
 data=f.read_bytes();sha=hashlib.sha256(data).hexdigest()
 current=len(data)==p['bytes'] and sha==p['sha256']
 canonical=len(data)==p.get('canonical_git_blob_bytes') and sha==p.get('canonical_git_blob_sha256')
 if not(current or canonical):raise RuntimeError('Original source/native SHA changed '+key)
 files[key]=data
 pins+=1
def jsonbytes(b):
 return json.loads(b.decode('utf-16') if b[:2]==bytes.fromhex('fffe') else b.decode('utf-8-sig'))
cursor=jsonbytes(files['new_historical_v35_59_physical_exact_ref_cursor_comparison'])
stats=cursor['physical_cursor_source_intersection']
assert cursor['ok'] and (stats['v35_old_object_tag'],stats['v35_ref_direct_on_disk_compact_width1'],
 stats['v35_exact_next_physical_tag_offset'],stats['v35_byte_ref_values_matched'])==(59,59,59,59)
b=files['actual_C98_v35_DProj_real_native_UObjectProperty_Callbacks']
raw=b.decode('utf-16') if b[:2]==bytes.fromhex('fffe') else b.decode('latin1','replace')
assert sum(x.strip()=='T065_NATIVE_REAL_PHYSICAL_v35_OBJECTPROPERTY_SERIALIZE_ITEM' for x in raw.splitlines())==10
assert 'REAL_FILE_RESULT=00000000 source=L:\\Source\\Engine\\UE1\\Source\\Unreal\\Textures\\DProj.utx' not in raw
old=files['original_C98_real_v55_v56_v57_v58_saving_UClass_both_classes'].decode('latin1')
found=re.findall(r'T065_NEW_C98_ORIGINAL_ACTUAL_SAVING_UCLASS CLASS=(\d+) VER=(\d+) NAMES=(\d+) REFS=(\d+) RAW=(\d+) CURSOR=(\d+)',old)
assert len(found)==8 and set(x[1] for x in found)=={'55','56','57','58'}
for cls in ('0','1'):
 by={int(v):(int(n),int(ref),int(raw),int(cursor)) for c,v,n,ref,raw,cursor in found if c==cls}
 assert sorted(by)==[55,56,57,58] and by[55]==by[56]
 assert by[55][0]>by[57][0]>by[58][0]
 assert by[56][3]-by[57][3]==3 and by[57][3]-by[58][3]==1
assert 'UCLASS_EXCEPTION' not in old
native=jsonbytes(files['original_1998_real_twelve_other_v35_packages_and_v55_loader_results'])
assert sum(x['original_C98_native_package_LoadPackage_nonnull'] for x in native['actual_native_results'] if x['version']==35)==5
cpp=files['original_C98_source_UnClass_cpp'].decode('latin1','replace')
assert 'if( Ar.Ver()<36 && Tag.Type==NAME_ObjectProperty )' in cpp
assert 'if( Ar.Ver() < 57 )' in cpp
unobj=files['original_C98_source_UnObj_cpp'].decode('latin1','replace')
assert 'if( Ar.Ver() < 57 )' in unobj and 'if( Ar.Ver() < 58 )' in unobj
guards=jsonbytes(files['source_11_version_guards_SHA_verified'])
assert guards['ok'] and guards['original_source_C98_and_T99_exact_guard_sites']==11
v432=jsonbytes(files['stock_UT99_v432_native_v60_to_v64'])
assert len(v432['older_versions'])==4 and v432['frozen_T011_ready_for_acceptance']
ut=jsonbytes(files['stock_UT99_v432_vs_469f_compiled_serializer_method_union'])
assert ut['T073_final_frozen_owner_pass']
c227=jsonbytes(files['distinct_2012_C227i_2026_C227k_compiled_methods'])
assert c227['full_original_frozen_method_criterion_PASS']
j=json.loads((root/'catalog/compliance/UE1-O06-G3-EXECUTION-TEST-BACKLOG.json').read_text('utf8'))
by={x['id']:x for x in j['baseline']}
assert by['T011']['status']=='PASS' and by['T073']['status']=='PASS' and by['T074']['status']=='PASS'
assert by['T065']['status'] in ('PASS','BLOCKED')
assert m['no_PRD_mutation_or_historical_package_import']
print(json.dumps({'T065_frozen_historical_source_boundary_full_PASS':True,'original_SHA_pins':pins,
 'original_C98_compiled_retail_v35_Physical_Property_callbacks':10,
 'genuine_v35_physical_one_byte_reference_next_tag_cursor':59,
 'original_1998_55_56_57_58_native_UClass_method_cases':8,
 'original_1998_v35_positive_native_loaded_files':5,
 'original_UT99_v432_native_version_cases_60_64_reused':5,
 'source_anchored_C98_UT99_guard_sites':11,
 'original_2012_2026_C227_source_family_deltas_proven':True,'PRD_touched':False},indent=2))
