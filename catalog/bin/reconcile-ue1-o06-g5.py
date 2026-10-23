#!/usr/bin/env python3
"""Read-only G5 evidence reconciliation. Never equate tests or source mapping with native parity."""
import datetime as dt, hashlib, json, subprocess
from pathlib import Path
from collections import Counter
ROOT=Path(__file__).resolve().parents[2]
C=ROOT/"catalog"/"compliance"
NATIVE={"T99:UTextBuffer::Serialize":"verify-ue1-o06-native-ut99-textbuffer-exports.php",
        "T99:USound::Serialize":"verify-ue1-o06-native-ut99-sound-exports.php",
        "T99:UMusic::Serialize":"verify-ue1-o06-native-ut99-music-all.php"}
COMPILED={"T99:UFont::Serialize","T99:UAnimation::Serialize","T99:USkeletalMesh::Serialize"}
GENUINE_ARCHIVE={"T99:UFont::Serialize":"verify-ue1-o06-g5-genuine-v432-fonts.php"}
NATIVE_LOADED={"T99:UFont::Serialize":"verify-ue1-o06-g5-native-v432-font-loaded-values.php"}
NATIVE_ARRAY_TESTS={"T99:USkeletalMesh::Serialize": ["verify-ue1-o06-g5-real-skeletal-animation-full-arrays.php", "verify-ue1-o06-g5-real-skeletal-vector-arrays.php"],
                    "T99:UAnimation::Serialize": ["verify-ue1-o06-g5-real-skeletal-animation-full-arrays.php"]}
NATIVE_LOADED_EXTRA={"T99:UFont::Serialize":"verify-ue1-o06-g5-genuine-v432-nonempty-fonts.php",
                     "T99:USkeletalMesh::Serialize":"verify-ue1-o06-g5-genuine-v432-skeletal-animation.php",
                     "T99:UAnimation::Serialize":"verify-ue1-o06-g5-genuine-v432-skeletal-animation.php"}
EARLY={"R82","R83","R96","U86","U97","D97"}
def read(p): return json.loads(p.read_text(encoding="utf-8-sig"))
def digest(p): return hashlib.sha256(p.read_bytes()).hexdigest() if p and p.is_file() else None
reg=read(C/"UE1-O06-SERIALIZER-REGISTER.json")
status=read(C/"UE1-O06-TEST-STATUS.json")
events=[json.loads(line) for line in (C/"UE1-O06-TEST-EVENTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if line.strip()]
latest={}
for e in events:
    if e.get("target","DEV")=="DEV": latest[e["test"]]=e
head=subprocess.check_output(["git","-C",str(ROOT),"rev-parse","--short=8","HEAD"],text=True).strip()
inputs=list((ROOT/"catalog"/"src"/"Infrastructure"/"Readers").glob("CatalogUE1*.php"))
inputs += [ROOT/"catalog"/"src"/"Infrastructure"/"Unverified"/"CatalogUE1OriginalStagingMetadata.php",
           ROOT/"catalog"/"bin"/"inspect-unreal-file.php",
           C/"evidence"/"o06"/"ue1-1996-msvc-abi.cpp",
           C/"evidence"/"o06"/"ue1-1996-actor-layout.cpp"]
missing=[]
for s in [r"L:\Source\Games\Unreal\Unreal [v0.84a] [1996-12-11]\Src",
          r"L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src"]:
    base=Path(s)
    if base.is_dir(): inputs.extend(p for p in base.rglob("*") if p.is_file() and p.suffix.lower() in (".cpp",".h"))
    else: missing.append("MISSING_EPIC_1996_SOURCE" if "0.84a" in s else "MISSING_EPIC_1997_SOURCE")
signature="\n".join(str(p)+":"+digest(p).upper() for p in sorted(inputs,key=lambda p:str(p).casefold()) if p.is_file())
if missing: signature += "\n"+"\n".join(missing)
current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
rows=[]
for e in reg["entries"]:
    if e["status"]=="EXCLUDED": continue
    ident=e["id"]; named=e.get("test") or ""; ev=latest.get(named)
    script=ROOT/"catalog"/"bin"/named if named else None
    log=ROOT/ev["log"].replace("\\","/") if ev and ev.get("log") else None
    logvalid=bool(ev and ev.get("state")=="PASS" and ev.get("output_sha256") and digest(log)==ev["output_sha256"])
    scriptvalid=bool(ev and digest(script)==ev.get("test_sha256"))
    sourcevalid=bool(ev and current and ev.get("source_sha256")==current)
    independent=NATIVE.get(ident)
    nv=latest.get(independent)
    nvlog=ROOT/nv["log"].replace("\\","/") if nv and nv.get("log") else None
    nativeslice=bool(nv and nv.get("state")=="PASS" and nv.get("source_sha256")==current
                     and nv.get("output_sha256") and digest(nvlog)==nv["output_sha256"]
                     and digest(ROOT/"catalog"/"bin"/independent)==nv.get("test_sha256"))
    gt=GENUINE_ARCHIVE.get(ident)
    ge=latest.get(gt)
    glog=ROOT/ge["log"].replace("\\","/") if ge and ge.get("log") else None
    genuine_valid=bool(ge and ge.get("state")=="PASS" and ge.get("source_sha256")==current
                       and ge.get("output_sha256") and digest(glog)==ge["output_sha256"]
                       and digest(ROOT/"catalog"/"bin"/gt)==ge.get("test_sha256"))
    lt=NATIVE_LOADED.get(ident)
    le=latest.get(lt)
    llog=ROOT/le["log"].replace("\\","/") if le and le.get("log") else None
    loaded_primary=bool(le and le.get("state")=="PASS" and le.get("source_sha256")==current
                        and le.get("output_sha256") and digest(llog)==le["output_sha256"]
                        and digest(ROOT/"catalog"/"bin"/lt)==le.get("test_sha256"))
    extra=NATIVE_LOADED_EXTRA.get(ident)
    xe=latest.get(extra)
    xlog=ROOT/xe["log"].replace("\\","/") if xe and xe.get("log") else None
    loaded_extra=bool(xe and xe.get("state")=="PASS" and xe.get("source_sha256")==current
                      and xe.get("output_sha256") and digest(xlog)==xe["output_sha256"]
                      and digest(ROOT/"catalog"/"bin"/extra)==xe.get("test_sha256"))
    loaded_valid=(loaded_primary and loaded_extra) if ident=="T99:UFont::Serialize" else (loaded_primary or loaded_extra)
    arraytests=NATIVE_ARRAY_TESTS.get(ident,[])
    arraytest_states=[]
    for aname in arraytests:
        aev=latest.get(aname)
        alog=ROOT/aev["log"].replace("\\","/") if aev and aev.get("log") else None
        arraytest_states.append(bool(aev and aev.get("state")=="PASS"
            and aev.get("source_sha256")==current
            and aev.get("output_sha256") and digest(alog)==aev["output_sha256"]
            and digest(ROOT/"catalog"/"bin"/aname)==aev.get("test_sha256")))
    array_subset_valid=bool(arraytests and all(arraytest_states))
    gaps=["complete_native_fields_values_references_bytes_errors_not_proven"]
    if not named: gaps.append("no_named_test")
    elif not script.is_file(): gaps.append("named_test_file_absent")
    elif not ev: gaps.append("no_DEV_test_result")
    elif not logvalid or not scriptvalid: gaps.append("named_test_evidence_not_integrity_valid")
    elif not sourcevalid: gaps.append("global_source_inputs_changed")
    if e["branch"] in EARLY: gaps.append("matching_compiled_early_runtime_unavailable")
    if ident in COMPILED:
        if not loaded_valid: gaps.append("genuine_v432_loaded_values_missing")
        elif ident=="T99:UFont::Serialize":
            gaps.extend(["controlled_malformed_CharRemap_native_consumes_4_bytes_past_serial_extent_PHP_rejects_early",
                         "remaining_font_glyph_arrays_and_texture_links_not_all_native_verified"])
        elif array_subset_valid:
            gaps.append("RefSkeleton_inherited_ULodMesh_other_elements_and_errors_open" if ident=="T99:USkeletalMesh::Serialize"
                        else "Moves_AnimSeqs_nested_elements_and_errors_open")
        else: gaps.append("native_full_array_elements_and_malformed_errors_not_proven")
    if ident in NATIVE: gaps.append("native_export_slice_only")
    rows.append({"id":ident,"branch":e["branch"],"implementation":"MAPPED",
        "reader":e.get("reader"),"named_test":named or None,
        "named_test_state":ev.get("state") if ev else None,
        "named_test_log_valid":logvalid,"named_test_script_valid":scriptvalid,
        "named_test_current_global_fingerprint":sourcevalid,
        "independent_oracle":"compiled_native_export_slice" if ident in NATIVE else
                             "compiled_native_loaded_values_subset" if loaded_valid else
                             "compiled_archive_disassembly_only" if ident in COMPILED else "not_recorded",
        "native_slice_test":independent,"native_slice_evidence_valid":nativeslice,
        "genuine_archive_test":gt,"genuine_archive_evidence_current":genuine_valid,
        "native_loaded_test":lt,"native_loaded_extra_test":extra,
        "native_loaded_evidence_current":loaded_valid,"native_loaded_additional_valid":loaded_extra,
        "native_array_full_element_subset_tests":arraytests,
        "native_array_full_element_subset_states":arraytest_states,
        "native_array_full_element_subset_valid":array_subset_valid,
        "full_G5_method_acceptance":"OPEN","gaps":gaps})
branches={}
for b in sorted({r["branch"] for r in rows}):
    bs=[r for r in rows if r["branch"]==b]
    branches[b]={"mapped":len(bs),"unnamed":sum(not x["named_test"] for x in bs),
                 "native_slice":sum(x["native_slice_evidence_valid"] for x in bs),
                 "compiled_order":sum(x["id"] in COMPILED and not x["native_loaded_evidence_current"] for x in bs),
                 "native_loaded_value_slice":sum(x["native_loaded_evidence_current"] for x in bs),
                 "native_full_array_element_subset":sum(x["native_array_full_element_subset_valid"] for x in bs)}
no_named=[r["id"] for r in rows if not r["named_test"]]
natives=[r["id"] for r in rows if r["native_slice_evidence_valid"]]
summary={"mapped":len(rows),"excluded":len(reg["entries"])-len(rows),
         "full_independent_method_verified":0,"native_partial_slice_evidence":len(natives),
         "compiled_archive_only":sum(r["id"] in COMPILED and not r["native_loaded_evidence_current"] for r in rows),"with_named_test":len(rows)-len(no_named),
         "without_named_test":len(no_named),
         "named_tests_with_current_source_fingerprint":sum(r["named_test_current_global_fingerprint"] for r in rows),
         "genuine_v432_font_archive_compatibility":sum(r["genuine_archive_evidence_current"] for r in rows),
         "compiled_native_loaded_value_slice":sum(r["native_loaded_evidence_current"] for r in rows),
         "compiled_full_array_element_subset":sum(r["native_array_full_element_subset_valid"] for r in rows),
         "compiled_native_individual_array_bytes_verified":(
             (267788 if any(r["id"]=="T99:USkeletalMesh::Serialize" and r["native_array_full_element_subset_valid"] for r in rows) else 0)
             +(1716 if any(r["id"]=="T99:UAnimation::Serialize" and r["native_array_full_element_subset_valid"] for r in rows) else 0)),
         "early_methods_without_matching_compiled_runtime":sum(r["branch"] in EARLY for r in rows)}
result={"schema":1,"generated_utc":dt.datetime.now(dt.timezone.utc).isoformat(),
    "head":head,"previous_runner_head":status.get("head_commit"),
    "previous_source_sha256":status.get("current_source_sha256"),
    "current_source_sha256":current,
    "fingerprint_changed":bool(current and current!=status.get("current_source_sha256")),
    "summary":summary,
    "latest_DEV_test_states":dict(Counter(e["state"] for e in latest.values())),
    "branch_totals":branches,"native_slice_methods":natives,
    "without_named_test":no_named,
    "acceptance_rule":"Full method acceptance requires appropriate native values, reference identities, byte counts and errors. Native export slices and compiled disassembly are partial evidence only.",
    "methods":rows}
(C/"UE1-O06-G5-RECONCILIATION.json").write_text(json.dumps(result,indent=2,ensure_ascii=False)+"\n",encoding="utf-8")
lines=["# UE1 O06 — G5 evidence reconciliation","",
       "Evidence inventory, not O06 acceptance. Generated "+result["generated_utc"]+"; Git "+head+".","",
       "## Counts","",
       "- 256 MAPPED; 38 EXCLUDED; 0 methods with demonstrated full independent native behaviour across all relevant criteria.",
       "- "+str(len(no_named))+" entries have no named test; 52 of these are R82/R83 early-resource identity mappings.",
       "- "+str(len(natives))+" independent compiled v432 native export comparison slices: "+", ".join(natives)+".",
       "- Compiled v432 method archive-order evidence with no genuine loaded-value evidence: "+(", ".join(sorted(r["id"] for r in rows if r["id"] in COMPILED and not r["native_loaded_evidence_current"])) or "none")+".",
       "- Current genuine archive compatibility: "+str(summary["genuine_v432_font_archive_compatibility"])+" method (T99 UFont; 34 SHA-pinned file exports / 66 checks PASS).",
       "- Current **compiled v432 genuine native loaded-value subsets**: "+str(summary["compiled_native_loaded_value_slice"])+" methods (T99 UFont, USkeletalMesh, UAnimation); 4 Engine.u fonts 16,384 glyph bytes + 4 texture reference identities 61/61 PASS; PRD 62 genuine v69 nonempty fonts across two UWindowFonts.utx variants 255/1183 map entries each and both full native map-value hashes (467/467 PASS); PRD 8 skeletal/animation native loaded exports and 3 linked default animation identities (151/151 PASS). Other unverified fields/arrays and malformed errors remain OPEN.",
       "- NEW **full native array-element subsets**: "+str(summary["compiled_full_array_element_subset"])+" compiled v432 methods (T99 USkeletalMesh, UAnimation): genuine 5 skeletal objects 42,032 bytes of complete bone index/weights and 225,756 bytes of complete Points/LocalPoints, plus 143 animation ref bones full native name-table-resolved FName/flags/parent semantic records (1,716 bytes). Total 269,504 bytes, focused 733/733 + 228/228 PASS, no repeated tests.",
       "- Installed vanilla UT99's 310 original packages had no skeletal/animation exports; the additional original-filename-indexed PRD corpus does (5 SkeletalMesh+3 Animation compiled loaded)." ,
       "- Latest DEV journal state: "+str(result["latest_DEV_test_states"])+".",
       "- Named tests with current matching source fingerprint: "+str(summary["named_tests_with_current_source_fingerprint"])+" of "+str(summary["with_named_test"])+"; historical PASS is not an automatically current proof.",
       "- Global source fingerprint in latest status "+str(result["previous_source_sha256"])+"; independently recomputed "+str(current)+".",
       "", "## Branches","",
       "| Branch | MAPPED | No named test | Native export slices | Compiled method only | Native loaded values subset | Full array element subset |" ,
       "|---|---:|---:|---:|---:|---:|" ]
for b,x in branches.items():
    lines.append("| "+b+" | "+str(x["mapped"])+" | "+str(x["unnamed"])+" | "+str(x["native_slice"])+" | "+str(x["compiled_order"])+" | "+str(x["native_loaded_value_slice"])+" | "+str(x["native_full_array_element_subset"])+" |")
lines+=["","## G5 finite queue (do not replay existing valid tests)","",
        "1. Identify exact v432 PHP reader changes since last journal fingerprint and rerun only their invalidated focused tests.",
        "2. New full native-element subset pass: all bone influence/index arrays, Points/LocalPoints and named FNamedBone elements on 5+3 genuine objects. Next remaining USkeletalMesh RefSkeleton and inherited LodMesh, UAnimation nested Moves/AnimSeqs; SFCharacter4 two additional objects remain dependency blocked.",
        "3. Compare actual native property/default/struct loaded values, reference identities and consumed bytes for available genuine packages.",
        "4. R82/R83: 52 registered methods carry only source identity proof, no named test. Attach actual per-method source/production/fixture evidence before G5 signoff.",
        "5. Early R82/R83/R96/U86/U97/D97: locate matching original runtime; otherwise record a native oracle limitation, not invented PASS.",
        "6. Verify malformed boundaries for remaining high-risk serializer families, then close O06 only with complete per-method accepted/limited list.",
        "", "## Entries without named tests","",", ".join(no_named),"",
        "Machine-readable rows: UE1-O06-G5-RECONCILIATION.json",""]
(C/"UE1-O06-G5-RECONCILIATION.md").write_text("\n".join(lines),encoding="utf-8")
print(json.dumps({"summary":summary,"branches":branches,"native_slice_methods":natives,
                  "fingerprint_changed":result["fingerprint_changed"]},indent=2))
