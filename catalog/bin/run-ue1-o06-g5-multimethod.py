#!/usr/bin/env python3
"""UE1 G5: one discovery per genuine package, eight concurrent acceptance rows.

The durable per-package log is separate from the O06 test START/PASS/FAIL log.
Scanning class/export tables is NOT an independent compiled-engine oracle and
cannot mark a method ACCEPTED. Full proof must be attached explicitly later.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[2]
CAT=ROOT/"catalog"
COM=CAT/"compliance"
MANIFEST=COM/"UE1-O06-G5-MULTIMETHOD-PACKAGES.json"
SUMMARY=COM/"UE1-O06-G5-MULTIMETHOD-ACCEPTANCE.json"
EVENTS=COM/"UE1-O06-G5-MULTIMETHOD-EVENTS.jsonl"
SCANNER=CAT/"bin"/"scan-ue1-o06-g5-package-methods.php"
RECON=COM/"UE1-O06-G5-RECONCILIATION.json"
GATES=COM/"UE1-O06-G5-MULTIMETHOD-GATES.json"
NATIVE_SAME_LOAD=COM/"UE1-O06-G5-MULTIMETHOD-NATIVE-ONE-LOAD.json"

def sha(file):
    digest=hashlib.sha256()
    with file.open("rb") as f:
        for chunk in iter(lambda:f.read(2*1024*1024),b""):
            digest.update(chunk)
    return digest.hexdigest()

def timestamp():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")

def atomic_json(path,doc):
    path.parent.mkdir(parents=True,exist_ok=True)
    temp=path.with_name(path.name+".tmp")
    with temp.open("w",encoding="utf-8",newline="\n") as fp:
        json.dump(doc,fp,indent=2,ensure_ascii=False)
        fp.write("\n");fp.flush();os.fsync(fp.fileno())
    os.replace(temp,path)

def event(name,status,hashvalue):
    with EVENTS.open("a",encoding="utf-8",newline="\n") as f:
        f.write(json.dumps({"utc":timestamp(),"kind":"PACKAGE_DISCOVERY_NOT_G5_TEST",
            "package":name,"state":status,"input_sha256":hashvalue},separators=(",",":"))+"\n")
        f.flush();os.fsync(f.fileno())

def render(rows,config,src_sha):
    target=config["targets"]
    previous=json.loads(RECON.read_text(encoding="utf-8"))
    recon={m["id"]:m for m in previous["methods"]}
    gates=json.loads(GATES.read_text(encoding="utf-8"))
    required=gates["required_criteria"]
    compiled=json.loads(NATIVE_SAME_LOAD.read_text(encoding="utf-8"))
    compiled_hits={m["method"]:m["hits"] for m in compiled["captured_methods"]}
    accepted=0
    goals={
      "T99:UObject::Serialize":"Close shared inherited StateFrame/property envelope and exact runtime error/byte semantics.",
      "T99:UClass::Serialize":"Prove actual compiled class defaults, references, metadata values and superclass invocation.",
      "T99:UTextBuffer::Serialize":"Native loaded 88 strings verified; inherited UObject and oversized native byte/error mismatch remain.",
      "T99:UFont::Serialize":"Prove all native font-page glyph arrays, texture refs and malformed CharRemap paths.",
      "T99:USkeletalMesh::Serialize":"Close inherited ULodMesh and remaining scalar/references/error semantics.",
      "T99:UAnimation::Serialize":"Verify inherited UObject and untested native/error/source-version boundaries.",
      "T99:USound::Serialize":"Go beyond native WAV exports to full source-defined serialized payload, values and error handling.",
      "T99:UMusic::Serialize":"Go beyond native exports to complete lazy-array seek semantics, native payload and errors."
    }
    methods=[]
    for method in target:
        archives=[]
        for package in rows:
            found=package["methods"].get(method)
            if found:
                archives.append({"key":package["key"],"objects":found["count"],
                  "sample_indices":found["sample_indices"]})
        record=recon.get(method,{})
        gate=gates["methods"].get(method,{})
        conditions=gate.get("criteria",{})
        all_verified=bool(required) and all(conditions.get(axis)=="VERIFIED" for axis in required)
        proof=gate.get("closure_artifact")
        proof_hash=gate.get("closure_sha256")
        proof_path=ROOT/proof if isinstance(proof,str) and proof.startswith("catalog/compliance/") else None
        proof_valid=bool(all_verified and proof_path and proof_path.is_file()
                         and isinstance(proof_hash,str) and sha(proof_path)==proof_hash)
        current_status="ACCEPTED" if proof_valid else "OPEN"
        if proof_valid:accepted+=1
        source_stats=[p.get("production_native_payloads",{}).get(method,{}) for p in rows]
        # Observation alone NEVER clears a requirement or makes a method ACCEPTED.
        methods.append({
          "id":method,"full_G5_method_acceptance":current_status,
          "ready_for_acceptance":proof_valid,
          "acceptance_criteria":conditions,
          "acceptance_criteria_met":sum(1 for a in required if conditions.get(a)=="VERIFIED"),
          "acceptance_criteria_total":len(required),
          "closure_artifact_verified":proof_valid,
          "compiled_same_real_load_dispatch_calls":compiled_hits.get(method),
          "mapped":record.get("implementation")=="MAPPED",
          "observed_qualified_package_count":len(archives),
          "observed_candidate_exports":sum(v["objects"] for v in archives),
          "packages":archives,
          "production_reader_export_ranges_inspected":sum(int(s.get("attempted",0)) for s in source_stats),
          "production_reader_native_records_complete":sum(int(s.get("complete",0)) for s in source_stats),
          "production_reader_errors":sum(int(s.get("errors",0)) for s in source_stats),
          "production_archive_bytes_inspected":sum(int(s.get("archive_bytes",0)) for s in source_stats),
          "prior_oracle_classification":record.get("independent_oracle","not_recorded"),
          "prior_gaps":record.get("gaps",[]),
          "next_closure_work":goals.get(method,"Attach complete compiled native method oracle."),
          "required_signoff":["pinned selected Epic source / compiled dispatch",
             "full native loaded fields and references","source-exact bytes and archive extent",
             "positive/negative runtime outcomes and error byte consumption",
             "all inherited serializers and selected game/version variants",
             "current affected test evidence","separately reviewed method acceptance"]
        })
    atomic_json(SUMMARY,{
      "schema":1,"updated_utc":timestamp(),"source_profile":config["default_profile"],
      "classification":"DISCOVERY_AND_FULL_METHOD_ACCEPTANCE_QUEUE_NOT_NATIVE_PROOF",
      "scanner_sha256":src_sha,
      "packages_scanned":len(rows),"packages_expected":len(config["packages"]),
      "distinct_fully_accepted_methods":accepted,
      "target_method_count":len(target),
      "target_method_acceptance_completed":accepted,
      "total_examined_export_rows":sum(row["export_count"] for row in rows),
      "total_production_export_ranges_inspected":sum(int(s.get("attempted",0)) for p in rows for s in p.get("production_native_payloads",{}).values()),
      "total_production_reader_records_complete":sum(int(s.get("complete",0)) for p in rows for s in p.get("production_native_payloads",{}).values()),
      "total_production_reader_errors":sum(int(s.get("errors",0)) for p in rows for s in p.get("production_native_payloads",{}).values()),
      "total_source_payload_bytes_inspected":sum(int(p.get("source_payload_bytes_inspected",0)) for p in rows),
      "single_compiled_native_load_evidence":"catalog/compliance/UE1-O06-G5-MULTIMETHOD-NATIVE-ONE-LOAD.json",
      "methods_observed_in_one_compiled_loader_invocation":len(compiled_hits),
      "package_observations":[{"key":p["key"],"sha256":p["sha256"],
             "archive_version":p["archive_version"],
             "export_count":p["export_count"],
             "reader_instance_count":p["reader_instance_count"]} for p in rows],
      "methods":methods,
      "do_not_infer":"Method counts are candidates derived from package metadata. Full acceptance requires seven independently VERIFIED gates and SHA-pinned method closure artifact. A source decoder record or native call count alone never accepts a method."
    })

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--status",action="store_true",help="read acceptance ledger; no package processing")
    ap.add_argument("--limit",type=int,default=0,help="only first N original package files")
    args=ap.parse_args()
    if args.status:
        if not SUMMARY.exists():
            print("NO_MULTIMETHOD_LEDGER_CREATED");return 1
        doc=json.loads(SUMMARY.read_text(encoding="utf-8"))
        print("G5_MULTI_STATUS scanned=%d/%d targets=%d FULL_ACCEPTED=%d"%
             (doc["packages_scanned"],doc["packages_expected"],
              doc["target_method_count"],doc["distinct_fully_accepted_methods"]))
        print("PRODUCTION_READER_PASS",doc.get("total_production_reader_records_complete",0),
              "of",doc.get("total_production_export_ranges_inspected",0),
              "source_bytes",doc.get("total_source_payload_bytes_inspected",0),
              "errors",doc.get("total_production_reader_errors",0))
        for row in doc["methods"]:
            print("METHOD",row["id"],row["full_G5_method_acceptance"],
                  "candidate_exports",row["observed_candidate_exports"],
                  "packages",row["observed_qualified_package_count"],
                  "production_native_complete",row.get("production_reader_native_records_complete",0),
                  "same_native_load_calls",row.get("compiled_same_real_load_dispatch_calls"))
        return 0
    config=json.loads(MANIFEST.read_text(encoding="utf-8"))
    workspace=Path(config["workspace"]);workspace.mkdir(parents=True,exist_ok=True)
    # NVMe scratch for current and future native compilers/debugger probes.
    build=Path("C:/Temp/UnrealDB/O06/build")
    build.mkdir(parents=True,exist_ok=True)
    for env_name in ("TMP","TEMP","TMPDIR"):
        os.environ[env_name]=str(build)
    scanner_sha=sha(SCANNER)
    rows=[]
    batch=config["packages"][:args.limit] if args.limit>0 else config["packages"]
    for spec in batch:
        key=spec["key"];path=Path(spec["path"])
        if not path.is_file():
            print("BLOCKED_MISSING_ORIGINAL",key,path);return 2
        true_sha=sha(path)
        if true_sha!=spec["sha256"]:
            print("BLOCKED_ORIGINAL_SHA_MISMATCH",key,true_sha);return 3
        cache=workspace/(key+".json")
        old=json.loads(cache.read_text(encoding="utf-8")) if cache.exists() else {}
        if old.get("sha256")==true_sha and old.get("scanner_sha256")==scanner_sha:
            print("UNCHANGED_PACKAGE_SKIP",key,"no serializer re-parse")
            result=old
        else:
            print("PACKAGE_START",key,"independent_methods",len(config["targets"]),flush=True)
            event(key,"STARTED",true_sha)
            try:
                process=subprocess.run(["php",str(SCANNER),str(path),key],
                    cwd=str(ROOT),capture_output=True,text=True,timeout=60)
                if process.returncode:
                    raise RuntimeError(process.stderr.strip() or process.stdout[:300])
                result=json.loads(process.stdout)
                if result["sha256"]!=true_sha or not result["valid_package"]:
                    raise RuntimeError("Original source SHA or package validator mismatch")
                result["scanner_sha256"]=scanner_sha
                atomic_json(cache,result)
                event(key,"PASS",true_sha)
                print("PACKAGE_PASS",key,"exports",result["export_count"],
                     "mapped_methods",len(result["methods"]),flush=True)
            except Exception as e:
                event(key,"FAIL",true_sha)
                print("PACKAGE_FAIL",key,str(e),file=sys.stderr,flush=True)
                return 4
        rows.append(result)
        # Every passed package is checkpointed into the independent method
        # rows immediately; timeout never erases completed observations.
        render(rows,config,scanner_sha)
    final=json.loads(SUMMARY.read_text(encoding="utf-8"))
    print("G5_MULTI_COMPLETE packages=%d targets=%d FULL_ACCEPTED=%d summary=%s"%
          (len(rows),len(config["targets"]),final["distinct_fully_accepted_methods"],SUMMARY))
    return 0

if __name__=="__main__":
    sys.exit(main())
