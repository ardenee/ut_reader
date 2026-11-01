#!/usr/bin/env python3
"""T075 source/native read-only original C98/C227 seals + genuinely new UT99 pointer/size evidence; no test replays."""
from pathlib import Path
import json,hashlib,collections
root=Path(__file__).resolve().parents[2]
manifest=root/'catalog/compliance/evidence/o06/20261101-G3-T075-original-UT99-6153-pointer-and-8707-runtime-linked-element-closure.json'
e=json.loads(manifest.read_text(encoding='utf8'))
assert e['schema']=='UE1-O06-G3-T075-final-original-source-native-linked-reference-and-runtime-only-property-size-v1'
pin_count=0
for key,pin in e['pins'].items():
 p=(root/pin['path']) if pin['location']=='repo' else Path(pin['path'])
 if not p.is_file():
  raise RuntimeError('T075 original/source pin missing: '+key)
 data=p.read_bytes()
 exact=len(data)==pin['bytes'] and hashlib.sha256(data).hexdigest()==pin['sha256']
 canonical=(len(data)==pin.get('canonical_git_blob_bytes')
            and hashlib.sha256(data).hexdigest()==pin.get('canonical_git_blob_sha256'))
 if not(exact or canonical):raise RuntimeError('T075 SHA source/native altered: '+key)
 pin_count+=1
def result(key):
 p=root/e['pins'][key]['path']
 data=p.read_bytes()
 return json.loads(data.decode('utf-16') if data[:2]==bytes.fromhex('fffe') else data.decode('utf8-sig'))
ref=result('pointer_exact_match_focused_pass_result')
siz=result('runtime_property_exact_size_focused_pass_result')
assert ref['ok'] and ref['checks']==27271 and ref['exact_original_runtime_pointer_target_matches']==6153
assert siz['ok'] and siz['assertions']==60952 and siz['native_linked_properties']==8707
assert e['total_new_distinct_source_native_check_assertions']==ref['checks']+siz['assertions']
assert e['prior_original_C98_C227_original_native_proofs_reused_not_reexecuted'] is True
assert e['actual_property_ElementSize_is_not_UProperty_Serialize_saved_archive_member'] is True
backlog=json.loads((root/'catalog/compliance/UE1-O06-G3-EXECUTION-TEST-BACKLOG.json').read_text('utf8'))
b={r['id']:r for r in backlog['baseline']}
assert all(b[f'T{x:03}']['status']=='PASS' for x in range(34,41))
assert b['T072']['status']=='PASS'
assert b['T076']['status']=='PASS'
print(json.dumps({'ok':True,'new_native_original_shipped_pointer_targets':6153,
 'new_native_original_linked_property_sizes':8707,
 'new_source_native_oracle_assertions':88223,'pins_sha256_valid':pin_count,
 'source_selected_profiles':['ut99-v432','ut99-v469f'],
 'prior_compiled_C98_C227_native_pointer_evidence_reused':True,
 'unarchived_runtime_only_ElementSize_not_presented_as_saved_bytes':True,
 'full_T073_and_T074_not_inferred':True},indent=2))
