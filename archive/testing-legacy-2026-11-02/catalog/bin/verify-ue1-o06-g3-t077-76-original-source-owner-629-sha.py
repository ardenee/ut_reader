from pathlib import Path
import json,hashlib,collections,subprocess,difflib
r=Path(__file__).resolve().parents[2]
c=r/'catalog/compliance';e=c/'evidence/o06'
j=json.loads((c/'UE1-O06-G3-EXECUTION-TEST-BACKLOG.json').read_text('utf8'))
by={x['id']:x for x in j['baseline']}
assert len(j['baseline'])==80
assert collections.Counter(x['status'] for x in j['baseline'][:76])=={'PASS':75,'SOURCE_NA':1}
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def valid(pin):
 p=Path(pin['path']);f=p if p.is_absolute() else r/p
 if not f.is_file():return 'MISSING',None
 raw=f.read_bytes();actual=hashlib.sha256(raw).hexdigest()
 if actual==pin.get('sha256'):
  size=pin.get('bytes')
  if size is None or int(size)==len(raw):return 'EXACT',f
  return 'SHA_EXACT_METADATA_LENGTH_STALE',f
 if actual==pin.get('canonical_git_blob_sha256') and len(raw)==pin.get('canonical_git_blob_bytes'):return 'CANONICAL',f
 return 'SHA_DIFF',f
tracked=collections.Counter();changes=[];rows={};bad=[]
for x in j['baseline'][:76]:
 id=x['id'];status=x['status']
 pins=x.get('verified_evidence') or []
 asserted=[]
 for pin in pins:
  if not isinstance(pin,dict) or 'path' not in pin:continue
  v,p=valid(pin);tracked[v]+=1
  if v=='SHA_DIFF' and pin['path']=='catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php' and id in ('T032','T033','T049'):
   changes.append((id,pin))
   asserted.append({'path':pin['path'],'status':'EXACT_HISTORIC_SOURCE_REVISION_ONE_NARROW_469F_V70_BRANCH'})
  elif v not in ('EXACT','CANONICAL','SHA_EXACT_METADATA_LENGTH_STALE'):
   bad.append((id,v,pin['path']))
  else:
   asserted.append({'path':pin['path'],'status':v})
 rows[id]={'source_requirement':x['pass_criterion'],'state':status,
          'source_build':x['profiles'],'verified_native_and_source_evidence':asserted}
assert not bad,bad[:12]
assert sum(tracked.values())==629,sum(tracked.values())
assert len(changes)==3 and {k for k,_ in changes}=={'T032','T033','T049'}
assert tracked['SHA_DIFF']==3 and tracked['MISSING']==0
old_path='catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php'
old_blob=subprocess.run(['git','show','17b143aa:'+old_path],cwd=r,capture_output=True,check=True).stdout
new_blob=subprocess.run(['git','show','53455a26:'+old_path],cwd=r,capture_output=True,check=True).stdout
cur=(r/old_path).read_bytes()
old_original=old_blob.replace(b'\n',b'\r\n')
new_original=new_blob.replace(b'\n',b'\r\n')
assert hashlib.sha256(old_original).hexdigest()==changes[0][1]['sha256']
assert all(t['sha256']==hashlib.sha256(old_original).hexdigest() for id,t in changes)
assert hashlib.sha256(new_original).hexdigest()==hashlib.sha256(cur).hexdigest()
diff=list(difflib.SequenceMatcher(None,old_blob.splitlines(),new_blob.splitlines()).get_opcodes())
added=[];removed=[]
for tag,a,b,x,y in diff:
 if tag=='equal':continue
 assert tag=='insert',(tag,a,b,x,y)
 added+=new_blob.splitlines()[x:y]
assert len(added)==17,len(added)
snippet=b'\n'.join(added)
assert b'if($ut99_469f&&$version>69)' in snippet and b'static_array_dim' in snippet
assert b'NetSerializeItem' not in snippet and b'pointerproperty' not in snippet.lower() and b'buttonproperty' not in snippet.lower()
for id in ('T032','T033','T049'):
 assert by[id]['status']=='PASS'
 assert id not in ('T073',)
 assert 'arrayproperty' not in by[id]['fixture'].lower() or id=='T049'
diff_method=json.loads((e/'20261101-G3-T073-final-original-v432-469f-complete-source-method-parity.json').read_text('utf8'))
assert diff_method['T073_final_frozen_owner_pass']
recent={
 'T065':'new_2026_11_02_complete_T065_original_source_native_historical_version_matrix',
 'T072':'original_C98_full_T072_terminal_evidence',
 'T073':'new_2026_11_01_T073_complete_immutable_original_12557_export_native_source_method_union',
 'T074':'new_2026_11_01_T074_full_frozen_method_source_native_acceptance',
 'T075':'T075_original_compiled_final_full_pass'
}
for id,key in recent.items():
 rec=by[id][key]
 if id=='T072': pins=[v for v in rec.values() if isinstance(v,dict) and 'path' in v]
 elif id=='T075':pins=[rec['new_exact_SHA_pinned_source_native_evidence']]
 else:pins=[rec]
 check=[]
 for pin in pins:
  code,p=valid(pin)
  if code not in ('EXACT','CANONICAL','SHA_EXACT_METADATA_LENGTH_STALE'):
   raise RuntimeError(str((id,pin,code)))
  check.append({'path':pin['path'],'sha256':pin['sha256'],'bytes':pin.get('bytes'),'sha_result':code})
 rows[id]['verified_native_and_source_evidence']=check
assert all(rows[id]['verified_native_and_source_evidence'] for id in rows)
assert rows['T050']['state']=='SOURCE_NA' and by['T057']['status']=='PASS'
assert 'SOURCE-JUSTIFIED N/A' in by['T050']['result']
assert all(x['status']=='PASS' for x in j['baseline'][:76] if x['id']!='T050')
for x in j['baseline'][:76]:
 assert 'mismatch' not in str(x.get('blocking_reason','') or '').lower()
scope={'old_commit':'17b143aa','new_commit':'53455a26',
 'historical_original_worktree_sha256':hashlib.sha256(old_original).hexdigest(),
 'current_worktree_sha256':hashlib.sha256(cur).hexdigest(),
 'only_git_source_revision_delta_added_lines':len(added),
 'source_only_guard':'$ut99_469f && $version>69 in arrayproperty property-byte-LOAD, not POINTER/ANY/BUTTON SerializeItem or network NetSerializeItem/Should',
 'affected_prior_T032_T033_T049_method_semantics':False,
 'new_469f_v70_source_delta_accepted_by_frozen_T073':True,
 'retains_old_sha_history_without_repining':True}
result={'schema':'UE1-G3-T077-independent-original-native-source-owner-immutable-SHA-join-v1',
 'date':'2026-11-02','frozen_owner':'T077','upstream_fixed_original_test_count':76,
 'upstream_statuses':{'PASS':75,'SOURCE_NA':1,'BLOCKED':0,'FAIL':0},
 'original_legacy_evidence_SHA_references_visited':629,
 'legacy_sha_verified_matching_pins':626,
 'historical_original_source_revision_only_3_transitive_pins':3,
 'transitive_source_revision_scope':scope,
 'five_aggregate_frozen_original_compiled_gates_historical_T065_T072_T073_T074_T075_sha_verified':True,
 'original_native_tests_relaunched':0,
 'no_unexplained_evidence_mismatch':True,'T050_explicit_source_proven_exclusion_with_T057_same_method':'T050 Core.u stock impossible mixed FName+UObject single script, real C98 Engine.u T057 passed',
 'T077_pass_frozen_original_method_owner_evidence':True,
 'not_whole_G5_or_PRD_release':True,
 'per_original_test_proof':rows}
out=e/'20261102-G3-T077-76-original-test-method-owner-629-SHA-references.json'
assert out.is_file()
saved=json.loads(out.read_text('utf8'))
assert saved==result,'T077 immutable source/native per-test original evidence manifest has changed'
print(json.dumps({'T077_original_source_owner_immutable_join_PASS':True,'all_upstream_terminal':76,
 'original_SHA_references_checked':629,'exact_current_or_prior_source_versions':dict(tracked),
 'five_new_aggregate_manifest_typed_sha_verified':True,
 'source_change_only_original_469f_v70_arrayproperty_added_lines':17,
 'no_unexplained_mismatch':True,'manifest_path':str(out.relative_to(r)),
 'sha256':digest(out)},indent=2))
