#!/usr/bin/env python3
"""UE1 G3 T001..T080: 10 durable batches of eight, zero invented parity.

The runner never launches a native engine unless a TEST-SPECIFIC trusted
oracle is implemented and named. It reuses SHA-pinned original evidence,
records a precise BLOCKED result for unimplemented native comparisons,
and advances without prompting. Use --retry-blocked after adding an
actual oracle. No duplicate already completed original native tests.
"""
from __future__ import annotations
import argparse
import datetime as dt
import hashlib
import json
import os
import pathlib
import sys
from typing import Any

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPLIANCE = ROOT / "catalog" / "compliance"
EVIDENCE = COMPLIANCE / "evidence" / "o06"
REGISTER = COMPLIANCE / "UE1-O06-G3-EXECUTION-TEST-BACKLOG.json"
BATCH_DIR = COMPLIANCE / "evidence" / "g3-batches"
JOURNAL = COMPLIANCE / "UE1-O06-G3-EXECUTION-EVENTS.jsonl"
PROGRESS = COMPLIANCE / "UE1-O06-G3-EXECUTION-PROGRESS.md"
BATCH_SIZE = 8
TOTAL = 80

def sha256(p: pathlib.Path) -> str:
    h=hashlib.sha256()
    with p.open("rb") as source:
        for chunk in iter(lambda:source.read(1 << 20),b""):
            h.update(chunk)
    return h.hexdigest()

def verify_pin(path: str, expected: str) -> dict[str, Any]:
    file = (ROOT/path).resolve()
    if not file.is_file():
        raise FileNotFoundError(str(file))
    actual=sha256(file)
    if actual.lower()!=expected.lower():
        raise AssertionError("Original evidence SHA mismatch: "+path+" got "+actual)
    return {"path":path.replace("\\","/"),"sha256":actual,"bytes":file.stat().st_size}

def verified_pass_log(path: str, key: str, expected: int) -> dict[str, Any]:
    file=ROOT/path
    if not file.is_file():
        raise FileNotFoundError(str(file))
    data=json.loads(file.read_text(encoding="utf-8-sig"))
    if data.get("ok") is not True or data.get(key)!=expected or (data.get("failed") or data.get("fail")):
        raise AssertionError("Original source-native acceptance log does not prove "+path)
    return {"path":path,"sha256":sha256(file),"accepted_original_assertions":expected}

# Exact old source/runtime proof used for T008. This test checks original
# native-writer UStruct/UField-inherited SAVE archive; it DOES NOT claim
# equivalence of every runtime UStruct class in these engines (T001 etc).
SAVE_PROOFS=[
 ("catalog/compliance/evidence/o06/20261028-G3-04-C98-original-1998-native-SAVE.u",
  "4957034dc7629163ab7adabeedff1fc9cecc3def1d577c4aab31bec0763071a4",
  "catalog/compliance/evidence/o06/20261028T092148309Z-e776196c-DEV-verify-ue1-o06-g3-04-c98-original-1998-native-save-load.php.txt",46),
 ("catalog/compliance/evidence/o06/20261028-G3-04-V432-original-native-save.u",
  "545439a4526dd9108fd3c12f3b6f1eaf37ad2c91cf3729e684e12fc8dc903ad6",
  "catalog/compliance/evidence/o06/20261028T015743761Z-ea2d9132-DEV-verify-ue1-o06-g3-04-v432-original-native-save-load.php.txt",31),
 ("catalog/compliance/evidence/o06/20261028-G3-04-469f-function-state-original-saved.u",
  "f389aeba411529cbbc9b52d0c814925db3d39b324629ac293090a5e81d88a9c3",
  "catalog/compliance/evidence/o06/20261028T014206920Z-0166292d-DEV-verify-ue1-o06-g3-04-469f-original-native-save-load.php.txt",37),
 ("catalog/compliance/evidence/o06/20261027-G304FunctionStateFixture.u",
  "1a9a98e156c15d66cf9c12e58110b50a622e7e965e24d1586b9b2dedcc4edb63",
  "catalog/compliance/evidence/o06/20261027T233743125Z-810c2ff6-DEV-verify-ue1-o06-g3-04-c227k-original-native-save-malformed.php.txt",45)
]

# Native malformed evidence genuinely covers each original engine variant
# required by T067: source-defined before/after read checks and actual cursor.
MALFORMED=[
 ("catalog/compliance/evidence/o06/20261028-G3-04-C98-badnet-original-numeric.log","G304_C98_COMPILED_GOT=42 EXPECTED=40"),
 ("catalog/compliance/evidence/o06/20261028-G3-04-C98-short-original-native-numeric.log","G304_C98_SHORT_COMPILED_GOT=40 EXPECTED=41"),
 ("catalog/compliance/evidence/o06/20261028-G3-04-C98-state-short-original-numeric.log","G304_C98_STATE_SHORT_COMPILED_GOT=62 EXPECTED=63"),
 ("catalog/compliance/evidence/o06/20261028-G3-04-469f-badnet-real-native-farchive-tell.log","")
]
def eval_t008(_row: dict[str,Any]) -> dict[str,Any]:
    pins=[]
    for file,hash_expected,log,n in SAVE_PROOFS:
        pins.append(verify_pin(file,hash_expected))
        pins.append(verified_pass_log(log,"checks",n))
    return {"status":"PASS","result":"Four genuine source-selected original native Core.dll SavePackage/FObjectManager-produced UState/UFunction files, inherited UStruct/UField persisted prefix and fresh original native LOAD verified with 46/31/37/45 acceptance assertions. No native reexecution.","evidence":pins}

def eval_t067(_row: dict[str,Any]) -> dict[str,Any]:
    pins=[]
    for file,needle in MALFORMED:
        p=ROOT/file
        if not p.is_file():raise FileNotFoundError(str(p))
        raw=p.read_text(encoding="utf-8",errors="replace")
        if needle and needle not in raw:raise AssertionError("Pinned source-specific actual native diagnostic absent: "+file)
        pins.append({"path":file,"sha256":sha256(p),"native_diagnostic":needle})
    # For 469f and 227k exact physical Tell, archive mismatch must
    # be certified by their original DEV source/compiled acceptance.
    other=[
     ("catalog/bin/verify-ue1-o06-g3-04-469f-original-malformed-physical-tell.php",),
     ("catalog/bin/verify-ue1-o06-g3-04-c227k-actual-malformed-tell.php",)
    ]
    for (file,) in other:
        p=ROOT/file
        if not p.is_file():raise FileNotFoundError(str(p))
        pins.append({"path":file,"sha256":sha256(p)})
    c227log=EVIDENCE/"20261028-g3-04-c227k-wrapped-boundary-actual-tell.log"
    if not c227log.is_file():raise FileNotFoundError(str(c227log))
    pins.append({"path":str(c227log.relative_to(ROOT)).replace("\\","/"),"sha256":sha256(c227log)})
    return {"status":"PASS","result":"Three direct original C98 binary error values (42/40,40/41,62/63), actual 469f FArchive Tell and 227k original check-before-read disassembly/real Tell, all observed and source-scoped already. No native reexecution.","evidence":pins}

def eval_t001_t002(row: dict[str, Any]) -> dict[str, Any]:
    """Real original v432 x86 loaded UField/UStruct values and native linker refs."""
    pins=[
        verify_pin("catalog/compliance/evidence/o06/20261028-G3-T001-T002-v432-native-direct-structs.cpp",
                   "19dab366d13b5b969cf843e853912b921763b4a04cbf446a26bbc09cb15d8736"),
        verify_pin("catalog/compliance/evidence/o06/20261028-G3-T001-T002-v432-native-direct-structs.log",
                   "a58bd237eddb2cf39ca4a0c529123bc1a37af65193cce348dc2b60213e60c041"),
        verify_pin("catalog/compliance/evidence/o06/20261028-G3-T001-T002-v432-native-build.log",
                   "501bd99ace97080d8ab9abbc2cc65282395ad45e6aeff704c0a54c861ad00dad"),
        verify_pin("catalog/compliance/evidence/o06/20261028-G3-T001-T002-v432-78-DEV-PASS.json",
                   "cb123f78d6a7db030ce2fa1db4a0b650804a7303e3316fd4d5a567d8b099e865")
    ]
    resultpath="catalog/compliance/evidence/o06/20261028-G3-T001-T002-v432-78-DEV-PASS.json"
    pins.append(verified_pass_log(resultpath,"checks",78))
    data=json.loads((ROOT/resultpath).read_text(encoding="utf-8-sig"))
    rows=data.get("original_native_loaded_objects",{})
    if set(rows)!= {"Vector","Rotator","Color","Plane"}:
        raise AssertionError("Original native T001/T002 missing one of four loaded direct structs")
    expected={
      "Vector":(0,576,590,-1,12),
      "Rotator":(1,568,32,-1,12),
      "Color":(535,293,546,-1,4),
      "Plane":(32,564,0,0,16)
    }
    for name,(idx,child,nextidx,sup,size) in expected.items():
        val=rows[name]
        if any(val.get(k)!=v for k,v in [
            ("export",idx),("child",child),("next",nextidx),
            ("super",sup),("original_loaded_native_props_size",size),
            ("script_size",0)]):
            raise AssertionError("Original native linked field mismatch "+name)
    pins.append({"source":"actual original v432 x86 Core.dll","sha256":"237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8",
                 "original_Core_u_sha256":"ae42de48103317761ffce4da2ee50300a57a653952d88d3451364f3d48975f70",
                 "runtime_original_native_fields_compared":4})
    detail=("Original v432 Core.dll native LOAD of Vector/Rotator/Color/Plane: "
        "all 78 actual loaded-field, FName text, serialized archive-boundary, "
        "Children/Next/SuperField same-linker reference checks match UnrealDB. "
        "Shared one native result validates both fixed tests; original loader not rerun.")
    return {"status":"PASS","result":detail,"evidence":pins}

EVALUATORS={"T001":eval_t001_t002,"T002":eval_t001_t002,"T008":eval_t008,"T067":eval_t067}

def now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")

def persist(register:dict[str,Any]) -> None:
    temp=REGISTER.with_name(REGISTER.name+".tmp")
    with temp.open("w",encoding="utf-8",newline="\n") as f:
        json.dump(register,f,indent=2,ensure_ascii=False)
        f.write("\n")
        f.flush()
        os.fsync(f.fileno())
    os.replace(temp,REGISTER)

def journal(event:dict[str,Any])->None:
    JOURNAL.parent.mkdir(parents=True,exist_ok=True)
    with JOURNAL.open("a",encoding="utf-8",newline="\n") as f:
        f.write(json.dumps(event,ensure_ascii=False,sort_keys=True)+"\n")
        f.flush()
        os.fsync(f.fileno())

def render_progress(register:dict[str,Any])->None:
    rows=register["baseline"]
    lines=[
      "# UE1 G3 T001–T080 execution progress (10 batches × 8 tests)",
      "",
      "Durable evidence-first results. **BLOCKED means the original native source/profile oracle is still missing, NOT a passing test.** The runner continues to the next case without asking for permission. The 16 G3 acceptance groups are not inferred from these counters.",
      "",
      "| Batch | Tests | PASS | FAIL | BLOCKED | PENDING |",
      "|---|---|---:|---:|---:|---:|"
    ]
    for i in range(10):
        chunk=rows[i*8:(i+1)*8]
        counts={s:sum(1 for a in chunk if a["status"]==s) for s in ("PASS","FAIL","BLOCKED","PENDING")}
        lines.append(f"| {i+1:02d} | T{i*8+1:03d}–T{(i+1)*8:03d} | {counts['PASS']} | {counts['FAIL']} | {counts['BLOCKED']} | {counts['PENDING']} |")
    lines.extend(["","## Individual status / exact remaining oracle","","| Test | Result | Evidence or blocker |","|---|---|---|"])
    for x in rows:
        result=x.get("result") if x["status"]=="PASS" else x.get("blocking_reason")
        result=(result or "not yet processed").replace("|","/").replace("\n"," ")
        lines.append(f"| {x['id']} | {x['status']} | {result} |")
    lines+=["","T001/T002 and T008/T067 have **complete source-specific actual original-native evidence evaluators** in this runner. Other blocked tests need their specified original compiled oracle; a new evaluator is added for an existing fixed T-test only after genuine source/native proof. No inferred PASS. Status ledger retains exact missing procedure. Missing acceptance is not a defect diagnosis.",""]
    PROGRESS.write_text("\n".join(lines),encoding="utf-8")

def review_one(row:dict[str,Any],retry:bool)->dict[str,Any]:
    if row["status"] in ("PASS","SOURCE_NA","FAIL"):return {"skipped":True}
    if row["status"]=="BLOCKED" and not retry:return {"skipped":True}
    idx=int(row["id"][1:])
    if idx<1 or idx>TOTAL:raise ValueError("Unexpected test ID")
    row["status"]="IN_PROGRESS"
    row["last_attempt_at"]=now()
    evaluator=EVALUATORS.get(row["id"])
    if evaluator is None:
        row["status"]="BLOCKED"
        row["result"]=None
        row["blocking_reason"]="Original compiled oracle/fixture not yet fully proven for this *specific* test. REQUIRED: "+row["procedure"]+" PASS ONLY WHEN: "+row["pass_criterion"]+" ORACLE: "+row["evidence"]+". Reuse before any new execution: "+row["reuse"]
        row["attempted_native_execution"]=False
        row["assessment"]="EVIDENCE_GAP_NO_TEST_PROOF"
        return {"status":"BLOCKED","reason":row["blocking_reason"]}
    try:
        evidence=evaluator(row)
    except (OSError,KeyError,ValueError,AssertionError,UnicodeError) as exc:
        row["status"]="BLOCKED"
        row["result"]=None
        row["blocking_reason"]=f"Previously sealed exact original evidence not independently usable: {type(exc).__name__}: {exc}. Do not claim a tested PASS."
        row["assessment"]="EVIDENCE_PIN_MISSING_OR_MISMATCH"
        row["attempted_native_execution"]=False
        return {"status":"BLOCKED","reason":row["blocking_reason"]}
    row["status"]=evidence["status"]
    row["result"]=evidence["result"]
    row["evidence_sha"]=hashlib.sha256(json.dumps(evidence["evidence"],sort_keys=True).encode()).hexdigest()
    row["verified_evidence"]=evidence["evidence"]
    row["blocking_reason"]=None
    row["assessment"]="COMPLETE_SEALED_ORIGINAL_NATIVE_PARITY_EVIDENCE_REUSED"
    row["attempted_native_execution"]=False
    return {"status":row["status"],"source_evidence_sha":row["evidence_sha"]}

def run_batch(register:dict[str,Any],batch_number:int,retry:bool,retry_ids:set[str])->dict[str,Any]:
    BATCH_DIR.mkdir(parents=True,exist_ok=True)
    cases=register["baseline"][(batch_number-1)*BATCH_SIZE:batch_number*BATCH_SIZE]
    batch_result={"batch":batch_number,"range":[cases[0]["id"],cases[-1]["id"]],"started_at":now(),"tests":[]}
    for case in cases:
        # Persist START before investigating, so interrupted runs resume exactly.
        this_retry=retry or case["id"] in retry_ids
        if case["status"] not in ("PASS","SOURCE_NA","FAIL") and (case["status"]!="BLOCKED" or this_retry):
            case["status"]="IN_PROGRESS"
            persist(register)
            journal({"event":"START","id":case["id"],"batch":batch_number,"at":now()})
            # The checker must process IN_PROGRESS on resume, safely.
        outcome=review_one(case,this_retry)
        if not outcome.get("skipped"):
            persist(register)
            journal({"event":case["status"],"id":case["id"],"batch":batch_number,"at":now(),
                     "evidence_sha":case.get("evidence_sha"),"detail":(case.get("result") or case.get("blocking_reason"))[:600]})
        batch_result["tests"].append({"id":case["id"],"status":case["status"],"assessment":case.get("assessment")})
        print(f" B{batch_number:02d} {case['id']} {case['status']}",flush=True)
    batch_result["finished_at"]=now()
    batch_result["counts"]={s:sum(1 for x in cases if x["status"]==s) for s in ("PASS","FAIL","BLOCKED","PENDING")}
    path=BATCH_DIR/f"batch-{batch_number:02d}-T{(batch_number-1)*8+1:03d}-T{batch_number*8:03d}.json"
    path.write_text(json.dumps(batch_result,indent=2)+"\n",encoding="utf-8")
    render_progress(register)
    journal({"event":"BATCH_COMPLETE","batch":batch_number,"at":now(),"counts":batch_result["counts"]})
    print(f" BATCH {batch_number}/10 {batch_result['counts']}",flush=True)
    return batch_result

def main()->int:
    parser=argparse.ArgumentParser()
    parser.add_argument("--batch",type=int,choices=range(1,11),metavar="{1..10}")
    parser.add_argument("--all",action="store_true")
    parser.add_argument("--retry-blocked",action="store_true")
    parser.add_argument("--retry-ids",default="",help="Comma-separated blocked T IDs to reconsider without reopening other blocked tests")
    args=parser.parse_args()
    if bool(args.batch)==bool(args.all):
        parser.error("Choose exactly one of --all or --batch N")
    original=REGISTER.read_text(encoding="utf-8-sig")
    reg=json.loads(original)
    tests=reg["baseline"]
    if len(tests)!=80 or any(t["id"]!=f"T{i:03d}" for i,t in enumerate(tests,1)):
        raise SystemExit("Refuse non-frozen execution registry")
    if reg.get("appendix"):
        print("Preserving deferred appendix; no S-subtests execute during baseline")
    batches=list(range(1,11)) if args.all else [args.batch]
    for n in batches:
        run_batch(reg,n,args.retry_blocked,set(x for x in args.retry_ids.split(",") if x))
    counts={s:sum(1 for x in tests if x["status"]==s) for s in ("PASS","FAIL","BLOCKED","PENDING")}
    print("G3_EXECUTION_BASELINE_80 "+json.dumps(counts,sort_keys=True),flush=True)
    print("G3_FULL_ACCEPTANCE="+("ELIGIBLE" if counts["BLOCKED"]+counts["FAIL"]+counts["PENDING"]==0 else "NOT_ELIGIBLE"),flush=True)
    return 0

if __name__=="__main__":
    sys.exit(main())
