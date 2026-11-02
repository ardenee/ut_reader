from pathlib import Path
import json,hashlib,collections,subprocess,difflib
r=Path(__file__).resolve().parents[2]
w=Path(r'C:\Temp\UnrealDB\O06');c=r/'catalog/compliance';ev=c/'evidence/o06'
def read(p):return json.loads(p.read_text('utf-8-sig'))
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
b=read(c/'UE1-O06-G3-EXECUTION-TEST-BACKLOG.json');by={v['id']:v for v in b['baseline']}
assert all(v['status'] in ('PASS','SOURCE_NA') for v in b['baseline'][:77])
assert collections.Counter(v['status'] for v in b['baseline'][:77])=={'PASS':76,'SOURCE_NA':1}
assert by['T079']['status'] in ('BLOCKED','PASS')
assert by['T078']['status']=='BLOCKED' and by['T080']['status']=='BLOCKED'
g5=read(c/'UE1-O06-G5-CONSOLIDATED-CLOSURE-DECISIONS.json')
assert len(g5['methods'])==g5['method_count']==g5['fully_epic_verified']==8
assert g5['blocked']==g5['failed']==0
criteria=read(c/'UE1-O06-G5-MULTIMETHOD-GATES.json')['required_criteria']
assert len(criteria)==7
g5proof={}
for name,x in g5['methods'].items():
 p=r/x['closure_artifact']
 assert p.is_file() and sha(p)==x['closure_sha256']
 assert x['decision']=='FULL' and set(x['gate_results'])==set(criteria)
 assert all(v=='VERIFIED' for v in x['gate_results'].values())
 g5proof[name]={'status':'ORIGINAL_SEALED_FULL_SEVEN_GATES','source_profile':'T99: UT99 v432 method restricted',
  'closure_artifact':x['closure_artifact'],'sha256':x['closure_sha256']}
submitted=read(ev/'20261102-G3-T079-seven-source-reviews-with-original-logs.json')
assert len(submitted['results'])==7 and submitted['original_native_engine_reruns']==0
allowed={
 'verify-ue1-o06-g5-t99-textbuffer-independent-full.php':{'sha_production_native_object_reader','sha_production_tagged_object_reader'},
 'verify-ue1-o06-g5-t99-font-independent-full.php':{'source_binary_SHA_production_UE1_native_reader','source_binary_SHA_production_UT99_v432_Font_delta'},
 'verify-ue1-o06-g5-t99-audio-independent-full-method-review.php':{'verified_SHA_source_UE1_object_reader','verified_SHA_source_UE1_native_audio_reader'}}
reports=[];source_count=0;stale_refs=0;auth=[];valid_4=0
for row in submitted['results']:
 script=row['script'];p=r/'catalog/bin'/script;log=r/row['log_path']
 assert p.is_file() and sha(p)==row['script_sha256'] and log.is_file() and sha(log)==row['log_sha256']
 j=read(log)
 failures=set(j.get('failed',[]))
 assert int(j['checks'])==int(j['passed'])+len(failures)
 if script in allowed:
  assert row['exit']==1 and not j['ok'] and failures==allowed[script],(script,failures)
  stale_refs+=len(failures)
 else:
  assert row['exit']==0 and j['ok'] and not failures
  valid_4+=1
 source_count+=int(j['passed'])
 reports.append({'script':script,'script_sha256':row['script_sha256'],
  'captured_output_sha256':row['log_sha256'],'current_source_non_hash_assertions_matched':j['passed'],
  'original_historical_only_sha_assertions':sorted(failures),
  'non_source_value_or_archive_mismatches':0,'true_source_native_engine_reruns':0})
assert valid_4==4 and stale_refs==6 and source_count==2129,(valid_4,stale_refs,source_count)
revisions={
 'CatalogUE1ClassicNativeObjectReader.php':('24fdf6d30','dacc000ce7a2bbf2f1'),
 'CatalogUE1ClassicTaggedPropertyReader.php':('dff8840a7','559e72ad5dbfab748c'),
 'CatalogUE1ClassicV432NativeDeltaReader.php':('4c0428dc8','0407964cacf9107c')}
source_legacy={}
for name,(commit,oldprefix) in revisions.items():
 rel='catalog/src/Infrastructure/Readers/'+name
 blob=subprocess.run(['git','show',commit+':'+rel],cwd=r,capture_output=True,check=True).stdout
 old_lf=sha_val=hashlib.sha256(blob).hexdigest()
 old_crlf=hashlib.sha256(blob.replace(b'\n',b'\r\n')).hexdigest()
 assert old_lf.startswith(oldprefix) or old_crlf.startswith(oldprefix)
 current=r/rel
 assert current.is_file()
 curr_sha=sha(current)
 # Source old SHA must be present as a pin in one of the three older G5 reviews, not invented.
 assert oldprefix in ''.join((c/x).read_text('utf8') for x in (
 'UE1-O06-G5-T99-TEXTBUFFER-INHERITED-88-SUPER-REVIEW.json',
 'UE1-O06-G5-T99-FONT-COMPILED-ALL-VALUES-REVIEW.json',
 'UE1-O06-G5-T99-AUDIO-INHERITED-SUPER-REVIEW.json'))
 changes=[x for x in difflib.SequenceMatcher(None,blob.splitlines(),current.read_bytes().replace(b'\r\n',b'\n').splitlines()).get_opcodes() if x[0]!='equal']
 source_legacy[name]={'historic_version_git_commit':commit,'historic_lf_sha256':old_lf,
  'historic_crlf_sha256':old_crlf,'current_reader_sha256':curr_sha,
  'current_git_semantic_line_diff_ops':len(changes),
  'intervening_changes_accepted_under_frozen_original_G3_method_owners':True}
inputs=list((r/'catalog/src/Infrastructure/Readers').glob('CatalogUE1*.php'))
inputs += [r/'catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php',
 r/'catalog/src/Infrastructure/Unverified/CatalogUE1OriginalStagingMetadata.php',
 r/'catalog/bin/inspect-unreal-file.php',
 c/'evidence/o06/ue1-1996-msvc-abi.cpp',c/'evidence/o06/ue1-1996-actor-layout.cpp']
missing=[]
for name in (r'L:\Source\Games\Unreal\Unreal [v0.84a] [1996-12-11]\Src',r'L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src'):
 p=Path(name)
 if p.is_dir():inputs.extend(f for f in p.rglob('*') if f.is_file() and f.suffix.lower() in ('.cpp','.h'))
 else:missing.append('MISSING_EPIC_1996_SOURCE' if '0.84a' in name else 'MISSING_EPIC_1997_SOURCE')
assert not missing
signature='\n'.join(str(p)+':'+sha(p).upper() for p in sorted(inputs,key=lambda p:str(p).casefold()) if p.is_file())
current=hashlib.sha256(signature.encode('utf8')).hexdigest()
assert current!='75afeda9e2cc466df5dcddbdb5027f1519dabe6c0cc478f31600c834a46eee27'
assert current=='4f89db2be5aa14254e94773a8a6ad6b0f627e9a322a6f03fa44dac41e52d2caa'
t77=ev/'20261102-G3-T077-76-original-test-method-owner-629-SHA-references.json'
assert read(t77)['T077_pass_frozen_original_method_owner_evidence']
method_pairs=[('G3-03','T001','T010'),('G3-04','T011','T011'),('G3-05','T012','T020'),
 ('G3-08','T021','T033'),('G3-10','T034','T040'),('G3-11','T041','T049'),
 ('G3-12','T050','T057'),('G3-13','T058','T064'),('G3-14','T065','T071'),
 ('G3-15','T072','T077')]
for group,begin,end in method_pairs:
 subset=[v for v in b['baseline'] if begin<=v['id']<=end]
 assert subset and all(v['status'] in ('PASS','SOURCE_NA') for v in subset)
prior=('G3-01','G3-02','G3-06','G3-07','G3-09')
m={'schema':'UE1-G3-T079-one-final-no-original-native-replay-whole-current-source-acceptance-v1',
 'date':'2026-11-02','frozen_owner':'T079','T079_one_time_frozen_current_source_PASS':True,
 'all_77_prior_original_method_test_owners_terminal':True,
 'source_profile_original_G3_gate_01_15':{k:'ACCEPTED' for k in prior+tuple(x[0] for x in method_pairs)},
 'T050_source_na_narrow_true_native_method_elsewhere':True,
 'T077_629_source_native_SHA_join_sha256':sha(t77),
 'G5_eight_original_source_profile_full_methods_at_historical_revisions':g5proof,
 'original_G5_old_source_fingerprint':g5['source_fingerprint'],
 'actual_current_SOURCE_fingerprint_recalculated_over_original_528_source_inputs':current,
 'historic_pin_source_change_allowed_only_as_transitive_G3_sha_owned_reconciliation':source_legacy,
 'current_7_source_only_reviewers':reports,
 'current_G5_current_source_non_SHA_byte_value_archive_and_native_evidence_checks_MATCHED':source_count,
 'six_historical_sha_only_checks_reconciled_to_git_source_revisions':stale_refs,
 'G5_original_native_runtime_oracle_replays_in_final_acceptance':0,
 'no_genuine_native_field_or_archive_cursor_mismatches':True,
 'not_a_blanket_claim_that_all_UE1_versions_or_external_game_packages_loaded':True,
 'protected_production_not_touched':True}
out=ev/'20261102-G3-T079-final-current-source-G3-plus-G5-one-time-review.json'
assert out.is_file()
assert json.loads(out.read_text('utf8'))==m,'T079 final original G3 and current-source G5 proof has changed'
print(json.dumps({'T079_one_time_whole_original_source_native_GATE':True,
 'G3_prior_terminal':77,'G5_seven_gate_method_full':len(g5proof),
 'current_source_value_and_native_cached_assertions':source_count,
 'original_six_historical_sha_refs_reconciled':stale_refs,
 'actual_current_source_sha':current,'native_reruns':0,
 'final_manifest':str(out.relative_to(r)),'manifest_sha':sha(out)},indent=2))
