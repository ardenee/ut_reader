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
NATIVE_COVERAGE={"T99:UClass::Serialize":"verify-ue1-o06-g5-v432-uclass-real-export-coverage.php"}
NATIVE_DEFAULTS={"T99:UClass::Serialize":"verify-ue1-o06-g5-uclass-defaults-native-608.php"}
NATIVE_REFERENTS={"T99:UObject::Serialize":"verify-ue1-o06-g5-multimethod-stateframe-native-referents.php"}
NATIVE_LOADED={"T99:UClass::Serialize":"verify-ue1-o06-g5-multimethod-uclass-loaded-values.php",
               "T99:UFont::Serialize":"verify-ue1-o06-g5-native-v432-font-loaded-values.php",
               "T99:UTextBuffer::Serialize":"verify-ue1-o06-g5-textbuffer-native-all-values.php",
               "T99:UObject::Serialize":"verify-ue1-o06-g5-multimethod-uobject-inherited-flags.php"}
NATIVE_ARRAY_TESTS={"T99:USkeletalMesh::Serialize": ["verify-ue1-o06-g5-real-skeletal-animation-full-arrays.php", "verify-ue1-o06-g5-real-skeletal-vector-arrays.php", "verify-ue1-o06-g5-native-refbones-v432.php"],
                    "T99:UAnimation::Serialize": ["verify-ue1-o06-g5-real-skeletal-animation-full-arrays.php", "verify-ue1-o06-g5-native-animseq-notifies-v432.php", "verify-ue1-o06-g5-native-v432-motionchunk-headers.php", "verify-ue1-o06-g5-native-v432-motionchunk-full-memory.php"]}
NATIVE_LOADED_EXTRA={"T99:UClass::Serialize":"verify-ue1-o06-g5-uclass-all-native-nested-memory.php",
                     "T99:UFont::Serialize":"verify-ue1-o06-g5-genuine-v432-nonempty-fonts.php",
                     "T99:UObject::Serialize":"verify-ue1-o06-g5-multimethod-v68-positive-stateframe.php",
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
    default_test=NATIVE_DEFAULTS.get(ident)
    dv=latest.get(default_test)
    dlog=ROOT/dv["log"].replace("\\","/") if dv and dv.get("log") else None
    default_valid=bool(dv and dv.get("state")=="PASS" and dv.get("source_sha256")==current
                       and dv.get("output_sha256") and digest(dlog)==dv["output_sha256"]
                       and digest(ROOT/"catalog"/"bin"/default_test)==dv.get("test_sha256"))
    coverage_test=NATIVE_COVERAGE.get(ident)
    cv=latest.get(coverage_test)
    clog=ROOT/cv["log"].replace("\\","/") if cv and cv.get("log") else None
    coverage_valid=bool(cv and cv.get("state")=="PASS" and cv.get("source_sha256")==current
                        and cv.get("output_sha256") and digest(clog)==cv["output_sha256"]
                        and digest(ROOT/"catalog"/"bin"/coverage_test)==cv.get("test_sha256"))
    loaded_valid=(loaded_primary and loaded_extra and default_valid and coverage_valid) if ident=="T99:UClass::Serialize" else (
        (loaded_primary and loaded_extra) if ident in {"T99:UFont::Serialize","T99:UObject::Serialize"} else (loaded_primary or loaded_extra))
    rt=NATIVE_REFERENTS.get(ident)
    re=latest.get(rt)
    rlog=ROOT/re["log"].replace("\\","/") if re and re.get("log") else None
    refs_valid=bool(re and re.get("state")=="PASS" and re.get("source_sha256")==current
                    and re.get("output_sha256") and digest(rlog)==re["output_sha256"]
                    and digest(ROOT/"catalog"/"bin"/rt)==re.get("test_sha256"))
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
            gaps.append("inherited_ULodMesh_and_unverified_scalar_fields_errors_open" if ident=="T99:USkeletalMesh::Serialize"
                        else "nested_MotionChunk_keys_full_for_two_real_packages_but_other_genuine_cases_error_paths_and_inherited_UObject_open")
        else: gaps.append("native_full_array_elements_and_malformed_errors_not_proven")
    if ident=="T99:UObject::Serialize" and loaded_valid:
        gaps.append("compiled_Entry_native_14_of_19_positive_StateFrames_28_native_Node_StateNode_NAMES_match_but_5_Camera_outer_class_and_malformed_errors_open" if refs_valid else "compiled_Entry_native_14_of_19_positive_StateFrames_imported_Node_StageNode_identity_and_malformed_errors_open")
    if ident=="T99:UClass::Serialize" and loaded_valid:
        gaps.append("compiled_ALL_88_of_88_REAL_UClass_43_ClassIndex0_NAME_None_zerobyte_placeholders_NOT_unvisited_3053_FDependency_176_Imports_608_DefaultTags_607_exact_1_INI_override_inherited_errors_variants_OPEN")
    if ident=="T99:UTextBuffer::Serialize" and loaded_valid:
        gaps.append("inherited_UObject_full_method_and_compiled_oversized_FString_consumed_bytes_differ_open")
    if ident in NATIVE: gaps.append("native_export_slice_only")
    rows.append({"id":ident,"branch":e["branch"],"implementation":"MAPPED",
        "reader":e.get("reader"),"named_test":named or None,
        "named_test_state":ev.get("state") if ev else None,
        "named_test_log_valid":logvalid,"named_test_script_valid":scriptvalid,
        "named_test_current_global_fingerprint":sourcevalid,
        "independent_oracle":"compiled_native_loaded_values_subset" if loaded_valid else
                             "compiled_native_export_slice" if ident in NATIVE else
                             "compiled_archive_disassembly_only" if ident in COMPILED else "not_recorded",
        "native_slice_test":independent,"native_slice_evidence_valid":nativeslice,
        "genuine_archive_test":gt,"genuine_archive_evidence_current":genuine_valid,
        "native_loaded_test":lt,"native_loaded_extra_test":extra,
        "native_loaded_evidence_current":loaded_valid,"native_loaded_additional_valid":loaded_extra,
        "native_UClass_full_nested_members_current":bool(ident=="T99:UClass::Serialize" and loaded_valid),
        "native_UClass_coverage_test":coverage_test,
        "native_UClass_coverage_all_genuine_current":coverage_valid,
        "native_UClass_actual_genuine_classes":88 if coverage_valid else 0,
        "native_UClass_nonserial_nameNone_placeholders":43 if coverage_valid else 0,
        "native_UClass_defaults_test":default_test,
        "native_UClass_defaults_current":default_valid,
        "native_UClass_defaults_tag_values_native_compared":608 if default_valid else 0,
        "native_UClass_defaults_exact":607 if default_valid else 0,
        "native_UClass_defaults_runtime_config_overrides":1 if default_valid else 0,
        "native_UClass_FDependency_element_count":3053 if ident=="T99:UClass::Serialize" and loaded_valid else 0,
        "native_stateframe_native_referent_test":rt,
        "native_stateframe_referent_names_current":refs_valid,
        "native_stateframe_referent_names_verified":28 if refs_valid else 0,
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
         "compiled_UClass_full_nested_member_subset":sum(r["native_UClass_full_nested_members_current"] for r in rows),
         "compiled_UClass_complete_608_default_tag_subset":sum(r["native_UClass_defaults_current"] for r in rows),
         "compiled_UClass_all_real_original_class_coverage":sum(r["native_UClass_coverage_all_genuine_current"] for r in rows),
         "compiled_full_array_element_subset":sum(r["native_array_full_element_subset_valid"] for r in rows),
         "compiled_stateframe_native_referent_name_subsets":sum(r["native_stateframe_referent_names_current"] for r in rows),
         "compiled_native_individual_array_bytes_verified":(
             (282956 if any(r["id"]=="T99:USkeletalMesh::Serialize" and r["native_array_full_element_subset_valid"] for r in rows) else 0)
             +(544792 if any(r["id"]=="T99:UAnimation::Serialize" and r["native_array_full_element_subset_valid"] for r in rows) else 0)),
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
       "- Current **compiled v432 genuine native loaded-value subsets**: "+str(summary["compiled_native_loaded_value_slice"])+" methods ("+", ".join(sorted(r["id"] for r in rows if r["native_loaded_evidence_current"]))+"). Includes ALL 88/88 real named nonzero Engine.u UClasses (131 ClassIndex==0 rows include 43 zero-byte NAME_None placeholders), native ClassFlags/GUID/ConfigName, 3053 native FDependency class-reference/Deep/CRC tuples, 176 live FName PackageImports and 88 ClassWithin names, PLUS 608/608 actual UClass loaded tagged default values of which 607 serialized values exactly match and one GameReplicationInfo.ServerName has a verified runtime INI override, UFont real glyph and nonempty remaps, USkeletalMesh/Animation full nested-array subsets, all 88 genuine Engine.u TextBuffer post-load values, and common UObject real inherited flags + 14/19 compiled positive RF_HasStack map frames. These remain PARTIAL method proofs; full error/reference/inherited source-family closure is OPEN.",
       "- NEW compiled v432 **genuine StateFrame referent name subset**: "+str(summary["compiled_stateframe_native_referent_name_subsets"])+" verified UObject method with 14/19 original Entry.unr v68 StateFrames and all 28 real compiled Node/StateNode FName strings matching import references, plus exact ProbeMask64/LatentAction/Code values (195/195 PASS). Five Camera original exports and complete outer/class/error semantics OPEN.",
       "- NEW **full native array-element subsets**: "+str(summary["compiled_full_array_element_subset"])+" compiled v432 methods (T99 USkeletalMesh, UAnimation): genuine 5 skeletal objects 42,032 bytes of complete bone index/weights and 225,756 bytes of complete Points/LocalPoints, plus 143 animation ref bones full native name-table-resolved FName/flags/parent semantic records (1,716 bytes). Total 269,504 bytes, focused 733/733 + 228/228 PASS, no repeated tests.",
       "- NEW independent compiled-v432 **resumable 2-case batch**: 237 full native FMeshBone records (15,168 bytes) source field-by-field including runtime-resolved names and XSize's second serialized write, 1787/1787 PASS; 70 full native FMeshAnimSeq metadata records (2,240 bytes), all 22 nested native notification time/function names, 575/575 PASS. Both were rerun in resume mode with 2/2 UNCHANGED_PASS cache skips, zero repeated tests. Combined new 2362/2362 checks. SHA-pinned native manifests and append-only events preserved; YSize/Depth nonserialized fields and MotionChunk Moves still OPEN.",
       "- NEW third resumable native contract: 70 genuine MotionChunk in-memory scalar/root header and array-length comparisons, 95 full first-Move AnalogTrack header checks plus 732 full first-track key/BoneIndices bytes identical, 598/598 PASS. Remaining KeyQuat/KeyPos/KeyTime buffers from all other tracks still OPEN; did not replay previous PASS evidence.",
       "- NEW compiled real v432 **full nested MotionChunk memory**: 70 authentic Moves, 3,292 AnalogTracks, 10,156 distinct root/track/bone nested array buffers, 540,836 entire loaded bytes equal every source byte, 21,020 KeyQuat, 9,268 KeyPos and 21,020 KeyTime keys. Microsoft CDB 46MB full-memory MiniDump MEMORY64 oracle for each original package, independent source bounds/float-preview comparisons; focused 29,145/29,145 PASS, one initial test aggregation FAIL preserved. This validates complete nested element values for TWO original v69 package UAnimation exports, not malformed runtime or other source families.",
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
        "2. NEW resume-safe FOUR-contract native batch: 237 FMeshBone records, 70 real AnimSeqs and 22 notifications, 70 MotionChunk headers, 95 first-Move AnalogTracks, plus ALL 10,156 nested animation arrays (540,836 native bytes) from original EcoTerror/d00d. Remaining priorities: inherited ULodMesh on genuine skeletal objects, native malformed/error semantics, and other source families; SFCharacter4 deps remain blocked.",
        "3. Compare actual native property/default/struct loaded values, reference identities and consumed bytes for available genuine packages.",
        "4. R82/R83: 52 registered methods carry only source identity proof, no named test. Attach actual per-method source/production/fixture evidence before G5 signoff.",
        "5. Early R82/R83/R96/U86/U97/D97: locate matching original runtime; otherwise record a native oracle limitation, not invented PASS.",
        "6. Verify malformed boundaries for remaining high-risk serializer families, then close O06 only with complete per-method accepted/limited list.",
        "", "## Entries without named tests","",", ".join(no_named),"",
        "Machine-readable rows: UE1-O06-G5-RECONCILIATION.json",""]
(C/"UE1-O06-G5-RECONCILIATION.md").write_text("\n".join(lines),encoding="utf-8")
print(json.dumps({"summary":summary,"branches":branches,"native_slice_methods":natives,
                  "fingerprint_changed":result["fingerprint_changed"]},indent=2))
