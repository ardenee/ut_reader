#!/usr/bin/env python3
"""Read-only original fixed G3-03..G3-13 source/error/version ownership audit.
Do not run native engine. Source version semantics NOT asserted beyond pinned
frozen owner proof; T065 and full T077 remain independent.
"""
from pathlib import Path
import json,hashlib,collections,re,sys
root=Path(__file__).resolve().parents[2]
ev=root/'catalog/compliance/evidence/o06'
reg=json.loads((root/'catalog/compliance/UE1-O06-G3-EXECUTION-TEST-BACKLOG.json').read_text('utf-8-sig'))
rows=reg['baseline'];by={r['id']:r for r in rows}
issues=[];n=0
def check(v,m):
 global n
 n+=1
 if not v:issues.append(m)
gates={
 'G3-03':(1,10),'G3-04':(11,11),'G3-05':(12,20),
 'G3-08':(21,33),'G3-10':(34,40),'G3-11':(41,49),
 'G3-12':(50,57),'G3-13':(58,64)}
fullrows=[]
for gate,(first,last) in gates.items():
 for i in range(first,last+1):
  tid=f'T{i:03d}';r=by[tid]
  check(r['gate']==gate and r['status'] in ('PASS','SOURCE_NA'),tid+' is not accepted for source method gate '+gate)
  check(bool(r.get('evidence_sha')) and bool(r.get('result')) and bool(r.get('pass_criterion')),tid+' missing immutable accepted criterion')
  proof=[p for p in r.get('verified_evidence',[]) if isinstance(p,dict) and p.get('path') and p.get('sha256')]
  check(bool(proof),tid+' no SHA-pinned actual source/native evidence')
  good=0
  for f in proof:
   file=root/f['path']
   if file.is_file() and hashlib.sha256(file.read_bytes()).hexdigest()==f['sha256']:
    good+=1
   else:issues.append(tid+' evidence SHA '+f['path'])
  check(good==len(proof),tid+' original recorded evidence drift')
  fullrows.append({'test_id':tid,'gate':gate,'status':r['status'],
      'source_profiles':r['profiles'],'unique_criterion':r['pass_criterion'],
      'accepted_original_test_evidence_sha':r['evidence_sha'],
      'native_or_original_source_proof_paths_sha_pinned':len(proof),
      'evidence_primary':proof[0]['path']})
check(len(fullrows)==64 and len({r['test_id'] for r in fullrows})==64,'source coverage does not partition exactly G3-03..13 fixed 64 IDs')
check(len({(by[r['test_id']]['procedure'],by[r['test_id']]['profiles']) for r in fullrows})==64,'duplicate original fixed test procedure+source family')
# Explicit source-defined error/branch outcomes from approved G3-03..13
# native gates. A source gate may have multiple names for distinct error modes;
# the primary owner remains one exact accepted original test and SHA.
branches={
 'G3-03':[
  ('UStruct invalid Children import-map physical cursor','T009'),
  ('UStruct malformed ScriptSize archive cursor','T009'),
  ('Nonzero C98/v432/C227 source Script.Num versus compact archive memory','T010'),
  ('Original inherited UStruct/UField SAVE source version layout','T008')],
 'G3-04':[
  ('v432 UFunction legacy pre-v64 default/header version60-63 native','T011')],
 'G3-05':[
  ('C98 corrupt superclass import/export native reject','T020'),
  ('UT99 corrupt UClass ClassWithin real import native reject','T020'),
  ('2026 C227k invalid UClass within imported source reject','T020'),
  ('Original C98/v432/C227i/C227k class default INI and source-protected tags','T019'),
  ('Old C98/v432 / distinct 227i/k native UClass source gates','T012')],
 'G3-08':[
  ('Original native FString invalid count physical EOF v432 vs C227k recovery','T028'),
  ('Old C98 fixed string vs new StrProperty source version branch','T025'),
  ('UTF16/nonBMP C227k signed-length source gate','T027'),
  ('Original source 469f pointer and 2026 C227k different SerializeItem','T032'),
  ('Original source 227k any struct/name/object loaded representations','T029')],
 'G3-10':[
  ('Nested UStruct genuine FString member physical EOF','T040'),
  ('Nested UStruct native FName member bad map index error/Tell','T040'),
  ('Struct inheritance and member type order original source versions','T039')],
 'G3-11':[
  ('Original v432 UArray count-negative and count-zero export mismatch cursor','T048'),
  ('Original v432 UArray count-overflow physical EOF error','T048'),
  ('Original 2026 C227k nested UArray FName invalid-index error','T048'),
  ('Original 2026 C227k signed negative count native allocator undefined behavior explicitly excluded','T048'),
  ('Original 2012 C227i missing compiled virtual ShouldSerializeItem despite SDK declaration','T049'),
  ('Old May1998 C98 UArrayProperty source-absent and excluded','T041')],
 'G3-12':[
  ('Original C98 illegal bytecode opcode physical error/Tell','T055'),
  ('Original 469f rejects legal historical C98 EndCode token','T056'),
  ('Original script nested typed FName and UObject reference memory reloc','T057'),
  ('Exact original C98 Core.u single script mixed FName+Object corpus unavailable, source N/A','T050')],
 'G3-13':[
  ('Original tagged property invalid type warn/recover source selected','T064'),
  ('Original source tagged property invalid array index error physical Tell','T064'),
  ('Original missing NAME_None terminator native FName error','T064'),
  ('Runtime config/localized source override versus unchanged original CDO','T062'),
  ('Original transient property original source skip vs persistent saved','T063')],
}
mapped=[]
for gate, items in branches.items():
 for branch,owner in items:
  row=by[owner]
  check(row['gate']==gate and row['status'] in ('PASS','SOURCE_NA'),branch+' has no terminal frozen same-gate owner')
  check(bool(row.get('evidence_sha')),branch+' owner absent native/source SHA')
  mapped.append({'source_defined_branch':branch,'gate':gate,'owner_test_id':owner,
   'owner_status':row['status'],'source_original_acceptance_sha':row['evidence_sha']})
check(len(mapped)==sum(map(len,branches.values())) and len(mapped)>=25,'malformed/version source branch inventory incomplete')
check(by['T050']['status']=='SOURCE_NA' and 'ZERO mixed' in by['T050']['result'],
      'source-justified only C98 Core.u unavailable input must not be treated as successful original native mixed instruction')
check('access violation' in by['T048']['result'] and 'excluded' in by['T048']['result'],
      'genuine original negative count allocator undefined behavior not labeled successful deterministic cursor')
# The old attempt to Serialize intrinsic, *unlinked* UClass is a defective native
# harness. Actual C98 Core.u-linked class tests succeeded; never count the
# intrinsic assertion as an engine-defined malformed-error branch.
check('assert Linker' in str(by['T071'].get('blocking_reason')) and 'invalid archive harness' in str(by['T071'].get('blocking_reason')),
      'original unlinked UClass fixture must be an explicit test-harness exclusion')
# The C98 unknown-object-property v34 source branch is under T065, not an
# unmapped G3-13 original version behavior. This cross-owner is still BLOCKED.
check(by['T065']['status']=='BLOCKED' and bool(by['T065'].get('partial_verified_evidence')),
      'crosscutting original C98 pre-v36 ObjectProperty version branch has no explicit T065 source owner')
unresolved_other=[t for t in ('T065','T072','T073','T074','T075','T077','T078','T079','T080') if by[t]['status']=='BLOCKED']
check(len(unresolved_other)==9 and by['T076']['status']=='PASS','crosscutting source-family requirements must remain blocked')
report={
 'scope':'Frozen approved G3-03 through G3-13 original-method source-error and version-branch owner allocation ONLY',
 'ok':not issues,'checks':n,'errors':issues,'original_compiled_engine_executions_now':0,
 'distinct_frozen_source_method_test_ids':len(fullrows),'original_acceptance_status_counts':dict(collections.Counter(r['status'] for r in fullrows)),
 'source_gate_counts':{g:b-a+1 for g,(a,b) in gates.items()},
 'version_and_malformed_source_branch_owner_count':len(mapped),
 'version_and_malformed_source_branch_owners':mapped,
 'every_original_fixed_64_test_has_exact_unique_criterion_and_sha':True,
 'identical_original_native_fixture_reuse_across_different_test_criteria':'explicitly allowed; no original native rerun or duplicate test procedure',
 'excluded_original_undefined_behavior':['2012/2026 source-selected C227k negative UArray count native allocator access violation has no deterministic FArchive Tell and cannot be a false successful serializer result'],
 'excluded_defective_test_harness':['1998 C98 native intrinsic unlinked UClass Linker assertion occurs before any retail package archive; superseded by original real Core.u-linked native UClass tests'],
 'explicit_older_source_branch_owned_not_pretend_complete':'T065 original C98 version34/35 unknown ObjectProperty Native FArchive branch remains G3-14 mandatory source gate and must remain BLOCKED',
 'not_a_full_original_C98_v34_archive_parity_claim':True,
 'no_claim_T065_T072_T073_T074_T075_T077_T078_T079_T080_passed':True,
 'frozen_test_rows':fullrows,
}
(ev/'20261101-G3-T071-original-64-frozen-errors-version-owner-reconciliation.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({k:v for k,v in report.items() if k not in ('frozen_test_rows','version_and_malformed_source_branch_owners')},indent=2))
sys.exit(0 if not issues else 1)
