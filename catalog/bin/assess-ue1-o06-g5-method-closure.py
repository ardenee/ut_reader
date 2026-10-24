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
  "reason":"Native UObject common positive StateFrame loader covered 14/19 source frames; five original Camera objects and malformed/error byte semantics unverified.",
  "next":"Obtain one native loader path that actually loads all 19 original Entry.unr StateFrames and malformed byte-error semantics; then perform single full-method review.",
  "blocker":"remaining_native_StateFrame_and_negative_paths",
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
  "result":"FAILED",
  "pinned":True,
  "current":True,
  "complete_members":True,
  "reason":"Known compiled-native 900-character malformed FString consumes beyond SerialSize then reports mismatch; production PHP safely rejects earlier with different consumed-byte semantics. Shared UObject still open.",
  "next":"Correct error/consumption parity safely (never unsafe out-of-bounds read), then one full acceptance run including inherited branch.",
  "blocker":"confirmed_native_vs_PHP_malformed_byte_consumption_mismatch",
 },
 "T99:UFont::Serialize":{
  "result":"FAILED",
  "pinned":True,
  "current":True,
  "reason":"Known native malformed CharRemap consumes four bytes beyond declared serial extent while PHP refuses before the read; other glyph references and native error handling not all closed.",
  "next":"Resolve exact malformed archive semantics safely, then one final complete Font acceptance run covering nonempty remaps and inherited behavior.",
  "blocker":"confirmed_native_vs_PHP_malformed_byte_consumption_mismatch",
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
        if name in ("T99:USound::Serialize","T99:UMusic::Serialize"):
            assert evidence["native_slice_evidence_valid"],name
        else:
            assert evidence["native_loaded_evidence_current"],name
    old=reg["methods"][name]
    gates={k:"OPEN" for k in criteria}
    if entry["pinned"]:gates["pinned_source_and_dispatch"]="VERIFIED"
    if entry.get("complete_members"):gates["complete_native_field_values_and_references"]="VERIFIED"
    if entry["result"]=="FAILED":gates["malformed_input_error_semantics"]="FAILED"
    assert entry["result"] in ("BLOCKED","FAILED")
    assert any(v!="VERIFIED" for v in gates.values())
    old["criteria"]=gates
    old["decision"]=entry["result"]
    old["decisive_blocker"]=entry["blocker"]
    old["next_action"]=entry["next"]
    old["reason"]=entry["reason"]
    old["last_consolidated_review"]="existing_current_SHA_pinned_evidence_only_no_new_tests"
    assert old["closure_artifact"] is None
    assert old["closure_sha256"] is None
    decisions[name]={
      "decision":entry["result"],"gate_results":gates,
      "existing_native_loaded_valid":evidence["native_loaded_evidence_current"],
      "existing_export_slice_valid":evidence["native_slice_evidence_valid"],
      "decisive_blocker":entry["blocker"],
      "reason":entry["reason"],"next_action":entry["next"],
      "new_native_tests_executed":0,"new_test_runs_executed":0,
    }
report={
 "schema":1,
 "policy":"Exactly one FINAL, SHA-current compiled-method acceptance decision per method. No further partial proof tests: only address listed decisive blocker, then run one complete acceptance test for the affected method. No automatic FULL from prior partial checks.",
 "source_fingerprint":reconciliation["current_source_sha256"],
 "method_count":len(decisions),"fully_epic_verified":0,
 "failed":sum(d["decision"]=="FAILED" for d in decisions.values()),
 "blocked":sum(d["decision"]=="BLOCKED" for d in decisions.values()),
 "new_oracle_runs":0,"new_test_runs":0,
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
 "new_test_runs":0,
 "failed_methods":[k for k,v in decisions.items() if v["decision"]=="FAILED"],
 "first_prerequisite":"T99:UObject::Serialize"},indent=2))
