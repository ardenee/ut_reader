#!/usr/bin/env python3
"""One final acceptance decision per G5 method, from existing SHA-current proofs.

No method probe or test is executed here. Passes are reused; mismatches are
final FAIL decisions until implementation changes. No FULL without 7 verified
criteria and a hash-pinned closure artifact. This replaces endless partial
oracle expansion with finite actionable verdicts.
"""
from __future__ import annotations
import datetime as dt
import hashlib
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
C=ROOT/"catalog"/"compliance"
G=C/"UE1-O06-G5-MULTIMETHOD-GATES.json"
R=C/"UE1-O06-G5-RECONCILIATION.json"
OUT=C/"UE1-O06-G5-CONSOLIDATED-CLOSURE-DECISIONS.json"
read=lambda p: json.loads(p.read_text(encoding="utf-8-sig"))
sha=lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
reg=read(G)
reconciliation=read(R)
by_id={r["id"]:r for r in reconciliation["methods"]}
assert set(reg["methods"]).issubset(by_id)
assert len(reg["required_criteria"])==7
criteria=reg["required_criteria"]
# Each method has ONE final disposition based on already captured evidence.
# Only fields supported by current compiled evidence receive VERIFIED.
status={
 "T99:UObject::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "reason":"New genuine compiled v432 UObject::LoadPackage in editor context re-confirmed 14 of 19 saved original v68 Entry StateFrames; five Camera exports (RF_NotForClient/Server, not public) were not actually instantiated by the compiled loader. Full 28 native Node/StateNode names retained. Three controlled native malformed offsets (-2,0,63) now match PHP using actual referenced Engine.Brush Script.Num=0; single consolidated method verdict PASS 43/43. Other complete tagged loaded property values, variant/error cases and final review still open.",
  "next":"For full method acceptance, establish native loaded tagged-property values and all relevant malformed property/tag/reference and archive-consumption outcomes; require loaded-only Camera distinction or genuine compiled preloading of their exports when in scope. Do not repeat 14 StateFrames, three code-offset native probes or consolidated 43/43.",
  "blocker":"remaining_loaded_tagged_UObject_values_other_error_paths_and_final_full_review",
 },
 "T99:UClass::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "reason":"All 88 genuine UClass objects and native stored scalar/array/default/inherited values verified; Script bytecode fixups, malformed consumption, source-version variants, and shared UObject not closed.",
  "next":"Resolve shared UObject prerequisite and full native bytecode/negative paths once in one final method-level test, not more scalar partials.",
  "blocker":"inherited_UObject_script_fixups_negative_paths",
 },
 "T99:UTextBuffer::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "complete_members":True,
  "reason":"Corrected: original compiled v432 malformed ANSI900 FString attempts Got 912 / Expected 843 and PHP now reports identical virtual archive extent and error without physically reading beyond the export; one consolidated verdict PASS 28/28. Inherited UObject is still BLOCKED.",
  "next":"Complete T99:UObject::Serialize full method acceptance, then review T99 UTextBuffer inheritance once; no new TextBuffer scalar or malformed tests.",
  "blocker":"inherited_UObject_full_method_unverified",
 },
 "T99:UFont::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "reason":"Original compiled v432 malformed CharRemap attempted Got4116 / Expected4112 and now PHP models identical logical byte count/error safely; ONE consolidated verdict PASS 23/23 using previous real native glyph/remap proofs. Shared inherited UObject and other malformed/source branches still open.",
  "next":"Complete T99:UObject::Serialize full method acceptance, then independently review Font inherited and remaining version/error paths once; do not repeat fonts positive array tests.",
  "blocker":"inherited_UObject_full_method_and_other_variant_errors",
 },
 "T99:USkeletalMesh::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "reason":"Native bone/vertex arrays covered, but inherited ULodMesh and other scalar/error branches unverified.",
  "next":"Resolve inherited ULodMesh from compiled engine and controlled negative cases in one complete method acceptance run.",
  "blocker":"inherited_ULodMesh_and_negative_cases",
 },
 "T99:UAnimation::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "reason":"Native 70 genuine MotionChunk arrays with 540,836 bytes compared, but inherited UObject, other versions and negative cases unverified.",
  "next":"Resolve shared UObject prerequisite and negative/variant cases before one final full Animation decision.",
  "blocker":"inherited_UObject_and_negative_variants",
 },
 "T99:USound::Serialize":{
  "result":"BLOCKED",
  "pinned":False,
  "current":True,
  "reason":"Independent compiled WAV exporter slice is output parity, not a complete compiled USound::Serialize loaded-value and error oracle.",
  "next":"Capture one complete native Sound load/error/byte-consumption oracle; one consolidated acceptance test only.",
  "blocker":"export_output_is_not_loader_native_semantics",
 },
 "T99:UMusic::Serialize":{
  "result":"BLOCKED",
  "pinned":False,
  "current":True,
  "reason":"Independent compiled music exporter slice is output parity, not full compiled UMusic::Serialize lazy-array/negative loader semantics.",
  "next":"Capture one complete native Music load/error/byte-consumption oracle; one consolidated acceptance test only.",
  "blocker":"export_output_is_not_loader_native_semantics",
 },
}
assert set(status)==set(reg["methods"])
decisions={}
for name in reg["methods"]:
    entry=status[name]
    evidence=by_id[name]
    if entry["current"]:
        # Following a localized TextBuffer-only production change, older
        # genuine native evidence still exists and remains hash-pinned but
        # does not automatically inherit the NEW global source fingerprint.
        # We do not replay tests just to make a BLOCKED decision appear green.
        # Current fingerprint is required only for any future FULL signoff.
        if evidence["native_slice_evidence_valid"] or evidence["native_loaded_evidence_current"]:
            pass
        else:
            assert entry["result"] in ("BLOCKED","FAILED"),name
            assert evidence["native_slice_test"] or evidence["native_loaded_test"] or evidence["native_loaded_extra_test"],name
            entry["current"]=False
    old=reg["methods"][name]
    gates={k:"OPEN" for k in criteria}
    if entry["pinned"]:gates["pinned_source_and_dispatch"]="VERIFIED"
    if entry.get("complete_members"):gates["complete_native_field_values_and_references"]="VERIFIED"
    if entry["result"]=="FAILED":gates["malformed_input_error_semantics"]="FAILED"
    if name in ("T99:UTextBuffer::Serialize","T99:UFont::Serialize"):
        events=[json.loads(line) for line in (C/"UE1-O06-TEST-EVENTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if line.strip()]
        test=("verify-ue1-o06-g5-textbuffer-consolidated-acceptance.php" if name=="T99:UTextBuffer::Serialize"
              else "verify-ue1-o06-g5-font-consolidated-acceptance.php")
        ev=next((item for item in reversed(events) if item.get("test")==test and item.get("state")=="PASS"),None)
        assert ev is not None,"Consolidated TextBuffer corrective proof missing"
        log=ROOT/ev["log"].replace("\\","/")
        file=ROOT/"catalog"/"bin"/test
        assert sha(log)==ev["output_sha256"] and sha(file)==ev["test_sha256"]
        if name=="T99:UTextBuffer::Serialize":
            assert reconciliation["source_font_branch_equivalence"]
            assert ev["source_sha256"]==reconciliation["middle_unmodified_branch_source_sha256"]
        else:
            assert reconciliation["source_uobject_positive_branch_equivalence"]
            assert ev["source_sha256"]==reconciliation["previous_uobject_positive_source_sha256"]
        result=read(log)
        if name=="T99:UTextBuffer::Serialize":
            assert result["one_final_method_acceptance"]=="BLOCKED" and result["php_native_error_identical"]
            assert result["native_compiled_oversized_attempted_bytes"]==912
        else:
            assert result["single_consolidated_method_decision"]=="BLOCKED" and result["error_matches_native"]
            assert result["native_attempted_bytes"]==4116 and result["declared_bytes"]==4112
        # The observed native error extent now matches. Do not mark
        # the WHOLE malformed-input criterion VERIFIED from a single case.
        gates["malformed_input_error_semantics"]="OPEN"
        gates["archive_bytes_and_consumption"]="VERIFIED"
    if name=="T99:UObject::Serialize":
        events=[json.loads(line) for line in (C/"UE1-O06-TEST-EVENTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if line.strip()]
        method_test="verify-ue1-o06-g5-uobject-consolidated-acceptance.php"
        pass_event=next((item for item in reversed(events) if item.get("test")==method_test and item.get("state")=="PASS"),None)
        assert pass_event is not None,"Missing single UObject compiled/native verdict"
        log=ROOT/pass_event["log"].replace("\\","/")
        verifier=ROOT/"catalog"/"bin"/method_test
        assert sha(log)==pass_event["output_sha256"] and sha(verifier)==pass_event["test_sha256"]
        assert pass_event["source_sha256"]==reconciliation["current_source_sha256"]
        result=read(log)
        assert result["ok"] and result["passed"]==43 and result["checks"]==43
        assert result["full_method_acceptance"]=="BLOCKED"
        assert result["genuine_original_StateFrames"]==19
        assert result["native_compiled_loader_positive_StateFrames"]==14
        assert result["native_malformed_offsets_php_exact_error_matches"]==3
        assert result["native_compiled_reruns_this_verification"]==0
        assert reconciliation["source_uobject_positive_branch_equivalence"]
        assert reconciliation["previous_uobject_positive_source_sha256"]
        gates["current_acceptance_tests"]="VERIFIED"
    assert entry["result"] in ("BLOCKED","FAILED")
    assert any(v!="VERIFIED" for v in gates.values())
    old["criteria"]=gates
    old["decision"]=entry["result"]
    old["decisive_blocker"]=entry["blocker"]
    old["next_action"]=entry["next"]
    old["reason"]=entry["reason"]
    old["last_consolidated_review"]=(
        "current_single_full_method_verdict_28_checks" if name=="T99:UTextBuffer::Serialize"
        else "current_single_full_method_verdict_23_checks" if name=="T99:UFont::Serialize"
        else "current_single_full_method_verdict_43_checks" if name=="T99:UObject::Serialize"
        else "existing_sha_pinned_evidence_only_no_new_tests")
    assert old["closure_artifact"] is None
    assert old["closure_sha256"] is None
    decisions[name]={
      "decision":entry["result"],"gate_results":gates,
      "existing_native_loaded_valid":evidence["native_loaded_evidence_current"],
      "existing_native_proof_current_global_fingerprint":entry["current"],
      "existing_export_slice_valid":evidence["native_slice_evidence_valid"],
      "decisive_blocker":entry["blocker"],
      "reason":entry["reason"],"next_action":entry["next"],
      "new_native_tests_executed":0,"new_test_runs_executed":1 if name in ("T99:UTextBuffer::Serialize","T99:UFont::Serialize","T99:UObject::Serialize") else 0,
    }
report={
 "schema":1,
 "policy":"Exactly one FINAL, SHA-current compiled-method acceptance decision per method. No further partial proof tests: only address listed decisive blocker, then run one complete acceptance test for the affected method. No automatic FULL from prior partial checks.",
 "source_fingerprint":reconciliation["current_source_sha256"],
 "method_count":len(decisions),"fully_epic_verified":0,
 "failed":sum(d["decision"]=="FAILED" for d in decisions.values()),
 "blocked":sum(d["decision"]=="BLOCKED" for d in decisions.values()),
 "new_oracle_runs":4,"new_test_runs":3,
 "methods":decisions,
}
OUT.write_text(json.dumps(report,ensure_ascii=False,indent=2)+"\n",encoding="utf-8",newline="\n")
G.write_text(json.dumps(reg,ensure_ascii=False,indent=2)+"\n",encoding="utf-8",newline="\n")
# Propagate the decisions to the existing eight-method dashboard without
# rescanning ANY package or rerunning ANY acceptance test.
acceptance_path=C/"UE1-O06-G5-MULTIMETHOD-ACCEPTANCE.json"
acceptance=read(acceptance_path)
assert {m["id"] for m in acceptance["methods"]}==set(decisions)
for item in acceptance["methods"]:
    result=decisions[item["id"]]
    item["full_G5_method_acceptance"]=result["decision"]
    item["decisive_closure_blocker"]=result["decisive_blocker"]
    item["next_closure_work"]=result["next_action"]
acceptance["failed_method_decisions"]=report["failed"]
acceptance["blocked_method_decisions"]=report["blocked"]
assert acceptance["distinct_fully_accepted_methods"]==0
acceptance_path.write_text(json.dumps(acceptance,ensure_ascii=False,indent=2)+"\n",encoding="utf-8",newline="\n")
print(json.dumps({"report":str(OUT.relative_to(ROOT)).replace("\\","/"),
 "methods":len(decisions),"FULL":0,"FAILED":report["failed"],"BLOCKED":report["blocked"],
 "new_test_runs":report["new_test_runs"],
 "failed_methods":[k for k,v in decisions.items() if v["decision"]=="FAILED"],
 "first_prerequisite":"T99:UObject::Serialize"},indent=2))
