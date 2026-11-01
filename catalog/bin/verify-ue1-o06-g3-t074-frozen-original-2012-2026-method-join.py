#!/usr/bin/env python3
"""Frozen T074 method-level source-native join: read-only, no native test replays."""
from pathlib import Path
import json,hashlib,collections
root=Path(__file__).resolve().parents[2]
file=root/'catalog/compliance/evidence/o06/20261101-G3-T074-final-original-C227i-C227k-frozen-method-criterion.json'
m=json.loads(file.read_text('utf8'))
b=json.loads((root/'catalog/compliance/UE1-O06-G3-EXECUTION-TEST-BACKLOG.json').read_text('utf8'))
by={v['id']:v for v in b['baseline']}
assert by['T074']['status']=='PASS' and by['T065']['status']=='BLOCKED'
assert collections.Counter(v['status'] for v in b['baseline'])=={'PASS':74,'SOURCE_NA':1,'BLOCKED':5}
assert by['T074']['fixture']=='Original 2012 Core.u 237 structs and 2026 Core/Engine method-loaded values'
assert m['full_original_frozen_method_criterion_PASS']
assert m['frozen_required_original_2012_Core_UStruct_objects']==237
assert m['original_2012_Engine_compiled_property_and_struct_counts']['property']==4268
assert m['original_2012_Engine_compiled_property_and_struct_counts']['struct']==1559
assert m['original_2026_Engine_compiled_property_and_struct_counts']['property']==6316
assert m['original_2026_Engine_compiled_property_and_struct_counts']['struct']==2408
assert m['original_2026_Core_compiled_ALL_nonempty_source_archives']['nonempty_exports']==1116
assert m['distinct_original_compiled_Core_methods']['2012']==33 and m['distinct_original_compiled_Core_methods']['2026']==37
assert m['original_C227i_excluded_ten_individual_objects_are_not_extra_serializer_methods']['not_fabricated_as_individually_native_loaded']
assert m['historical_pre36_T065_not_inherited'] and m['T077_and_final_G3_G5_release_not_automatically_accepted']
assert all(v['status'] in ('PASS','SOURCE_NA') for v in b['baseline'] if v['gate'] in ('G3-03','G3-04','G3-05','G3-08','G3-10','G3-11','G3-13'))
pins=0
for key,p in m['pins'].items():
 path=root/p['path'] if p['location']=='repo' else Path(p['path'])
 if not path.is_file():raise RuntimeError('Missing original source evidence '+key)
 data=path.read_bytes();sha=hashlib.sha256(data).hexdigest()
 exact=len(data)==p['bytes'] and sha==p['sha256']
 canonical=len(data)==p.get('canonical_git_blob_bytes') and sha==p.get('canonical_git_blob_sha256')
 if not(exact or canonical):raise RuntimeError('Changed SHA source/native evidence '+key)
 pins+=1
print(json.dumps({'T074_frozen_method_scope_PASS':True,'SHA256_original_source_evidence_verified':pins,
 'reused_original_2012_Core_method_objects':237,'reused_original_2012_Engine_method_objects':5827,
 'reused_original_2026_Engine_method_objects':8724,'reused_original_2026_Core_nonempty':1116,
 'extra_2012_physical_instances_not_falsely_native_verified':10,
 'remaining_T065_historical_version_cursor_BLOCKED':True,'no_PRD_release':True},indent=2))
