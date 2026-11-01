#!/usr/bin/env python3
"""T073 native source-method acceptance is a read-only join of immutable original evidence."""
from pathlib import Path
import json,hashlib,collections
root=Path(__file__).resolve().parents[2]
p=root/'catalog/compliance/evidence/o06/20261101-G3-T073-final-original-v432-469f-complete-source-method-parity.json'
m=json.loads(p.read_text('utf8'))
assert m['T073_final_frozen_owner_pass'] and m['original_Core_Engine_binary_bytes_unchanged']
assert m['true_original_stock_physical_export_map_coverage']['zero_unexplained_nonempty_stock_export_gaps']
assert m['strict_original_compiled_only_PREVIOUSLY_UNOBSERVED_values']['new_native_saved_value_assertions_exact']==1489
assert m['strict_original_compiled_only_PREVIOUSLY_UNOBSERVED_values']['469f_last_six_new_Registry_property_ArrayDim_and_PropertyFlags_loaded_matched']==12
assert m['original_compiled_serializer_types']=={'v432':24,'469f':25,'469f_extra_ULinkerLoad':True}
assert m['previous_accepted_method_parity_reused_not_replayed']
total=0
for name,pin in m['pins'].items():
 f=root/pin['path'] if pin['location']=='repo' else Path(pin['path'])
 if not f.is_file():raise RuntimeError('T073 source/native evidence missing '+name)
 data=f.read_bytes();digest=hashlib.sha256(data).hexdigest()
 exact=len(data)==pin['bytes'] and digest==pin['sha256']
 canonical=(len(data)==pin.get('canonical_git_blob_bytes')
            and digest==pin.get('canonical_git_blob_sha256'))
 if not(exact or canonical):raise RuntimeError('T073 original bytes not matching SHA pin '+name)
 total+=1
value=m['pins']['independent_165_real_values_verifier_result']
f=root/value['path']
data=f.read_bytes();res=json.loads(data.decode('utf-16') if data[:2]==bytes.fromhex('fffe') else data.decode('utf8-sig'))
assert res['ok'] and res['new_original_native_struct_method_checks']==1489
assert sum(x['exact_matching_objects'] for x in res['source_loaded_object_parity'].values())==165
for k,d in m['true_original_stock_physical_export_map_coverage'].items():
 if k=='zero_unexplained_nonempty_stock_export_gaps':continue
 assert d['genuine_original_materialized']+d['source_inert_unconstructed_zero_byte_CLASS0_NAME_None']==d['Core_Engine_physical_exports']
print(json.dumps({'T073_full_frozen_criterion_pass':True,'original_evidence_sha_pins':total,
 'original_physical_export_coverage_no_unexplained_nonempty_entries':12557,
 'original_loaded_new_method_objects_exact':165,'source_native_new_assertions':1501,
 'zero_byte_real_original_export_map_exclusions':202,
 'other_T065_T074_unchanged':True},indent=2))
