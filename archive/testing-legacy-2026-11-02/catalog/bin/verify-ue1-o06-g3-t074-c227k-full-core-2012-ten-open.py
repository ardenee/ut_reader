#!/usr/bin/env python3
"""Read-only, no native rerun: distinguish closed 2026 C227k Core from ten open 2012 C227i Core methods."""
from pathlib import Path
import hashlib,json,collections
r=Path(__file__).resolve().parents[2]
f=r/'catalog/compliance/evidence/o06/20261101-G3-T074-original-2026-227k-full-Core-2012-227i-10-real-obligations-open.json'
m=json.loads(f.read_text('utf8'))
assert m['full_T074_PASS'] is False
c=m['2026_C227k_source_native_complete']
assert (c['original_compiled_full_materialized_nonempty_Core_u'],
  c['full_true_original_loaded_UStruct_Class_Function_owners'],
  c['full_true_original_loaded_UProperty_owners'],c['genuine_original_source_field_assertions_PASS'])==(1116,289,791,4688)
old=m['2012_C227i_remaining']
assert old['uncovered_native_loaded_direct_UStruct_Class_Function_instances']==5
assert old['uncovered_native_loaded_direct_UProperty_instances']==5
valid=0
for key,p in m['pins'].items():
 path=r/p['path'] if p['location']=='repo' else Path(p['path'])
 if not path.is_file():raise RuntimeError('Missing original native proof '+key)
 data=path.read_bytes();sha=hashlib.sha256(data).hexdigest()
 if not((len(data)==p['bytes'] and sha==p['sha256']) or
  (len(data)==p.get('canonical_git_blob_bytes') and sha==p.get('canonical_git_blob_sha256'))):
  raise RuntimeError('Unpinned original native evidence: '+key)
 valid+=1
raw=(r/m['pins']['2026_original_source_selected_new_complete_Core_native_comparison_result']['path']).read_bytes()
res=json.loads(raw.decode('utf-16') if raw[:2]==bytes.fromhex('fffe') else raw.decode('utf-8-sig'))
assert res['ok'] and res['new_genuine_source_native_C227k_Core_assertions']==4688
assert res['source_native_postload_inherited_function_super_reference_not_archived']==1
print(json.dumps({'2026_C227k_original_Core_FULL_loaded_native_PASS':True,'real_Core_nonempty_exports':1116,
 'source_native_assertions':4688,'provenance_hashes_valid':valid,
 '2012_C227i_Core_still_unverified_original_method_objects':10,
 'frozen_T074_must_remain_BLOCKED':True},indent=2))
