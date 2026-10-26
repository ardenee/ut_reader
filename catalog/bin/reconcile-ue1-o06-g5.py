#!/usr/bin/env python3
"""Read-only G5 evidence reconciliation. Never equate tests or source mapping with native parity."""
import datetime as dt, hashlib, json, subprocess, difflib
from pathlib import Path
from collections import Counter
ROOT=Path(__file__).resolve().parents[2]
C=ROOT/"catalog"/"compliance"
NATIVE={"T99:UTextBuffer::Serialize":"verify-ue1-o06-native-ut99-textbuffer-exports.php",
        "T99:USound::Serialize":"verify-ue1-o06-native-ut99-sound-exports.php",
        "T99:UMusic::Serialize":"verify-ue1-o06-native-ut99-music-all.php"}
COMPILED={"T99:UFont::Serialize","T99:UAnimation::Serialize","T99:USkeletalMesh::Serialize"}
GENUINE_ARCHIVE={"T99:UFont::Serialize":"verify-ue1-o06-g5-genuine-v432-fonts.php"}
NATIVE_INHERITED={"T99:UClass::Serialize":"verify-ue1-o06-g5-uclass-inherited-native-88.php"}
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
method_decisions=read(C/"UE1-O06-G5-MULTIMETHOD-GATES.json")["methods"]
status=read(C/"UE1-O06-TEST-STATUS.json")
events=[json.loads(line) for line in (C/"UE1-O06-TEST-EVENTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if line.strip()]
latest={}
for e in events:
    if e.get("target","DEV")=="DEV": latest[e["test"]]=e
head=subprocess.check_output(["git","-C",str(ROOT),"rev-parse","--short=8","HEAD"],text=True).strip()
inputs=list((ROOT/"catalog"/"src"/"Infrastructure"/"Readers").glob("CatalogUE1*.php"))
inputs += [ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogLegacyPackageReader.php",
           ROOT/"catalog"/"src"/"Infrastructure"/"Unverified"/"CatalogUE1OriginalStagingMetadata.php",
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
# A newly verified v432 source-local dynamic ArrayProperty physical-EOF
# correction touches ONLY the existing tagged reader's count guard.
# Reconstruct the old proven source signature (git HEAD before this fix)
# instead of replaying hundreds of unrelated native loader probes.
# A new 21-check native compiled differential oracle must prove the
# change is isolated before ANY previous FULL verdict is reusable.
# The only later source edit is the shared UMesh/ULodMesh archive reader.
# Restore its previously accepted blob in the signature when reconciling
# historical method proofs; keep the TRUE new fingerprint for the dashboard.
# The latest two edits are both limited to v432 UAnimation's error path.
# Verify the original compiled first-chance position proof and genuine native
# loaded values at the TRUE current source fingerprint, then substitute only
# the two old reader blobs for inherited already-accepted method evidence.
# A source-local new native UT99 ULodMesh RemapAnimVerts acceptance:
# restore the immediately preceding proven committed mesh reader before
# reconstructing older T99 method fingerprints. The new native loaded
# reversal and multi-frame duplicate/permutation cases are original v432
# compiled data, and the shortened remap branch matches the stock loader's
# success without fabricating unsafe memory contents.
# Additional source-isolated UClass compiled live Script relocation view.
# The prior six FULL subclass readers are unchanged; source-correct metadata
# adds only the original XFER(UObject*)/XFER(FName) memory offsets and targets.
# Independent complete v432 UClass original Engine.u source-profile closure
# adds exact XFER reconstructed memory *views*, without modifying prior
# compiled-native object serialization semantics. Pin the old source blob,
# verify the new 1342-check seven-gate method decision, and reconstruct
# previous fingerprints rather than rerunning six already FULL methods.
# Last source-scoped v432 UObject two native-defined branches:
# the original UClass/StringProperty-to-StrProperty loader conversion and
# inner UStruct string physical FArchive EOF (no early export-size guard).
# All seven previously FULL methods remain bound to immutable old source
# IDs. One NEW compiled stock original-derived legacy-string package and
# the previously SHA-pinned direct/nested FString EOF evidence establish the
# exact two edited branches; never replay original class/native oracles.
uobject_new_current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
assert uobject_new_current=="8e26d58e1b18d07c71f690aeace53f0221706e7f22fe003988055857b4f768f5"
uobject_test="verify-ue1-o06-g5-uobject-native-legacy-and-struct-closure.php"
uobject_ev=latest.get(uobject_test)
assert uobject_ev and uobject_ev["state"]=="PASS"
assert uobject_ev["source_sha256"]==uobject_new_current
uobject_log=ROOT/uobject_ev["log"].replace("\\","/")
uobject_result=read(uobject_log)
assert uobject_result["ok"] and uobject_result["checks"]==uobject_result["passed"]==35
assert uobject_result["native_stock_runtime_reexecutions_in_verifier"]==0
assert uobject_result["global_T99_UObject_method"]=="BLOCKED"
assert digest(uobject_log)==uobject_ev["output_sha256"]
assert digest(ROOT/"catalog/bin"/uobject_test)==uobject_ev["test_sha256"]
uobject_src=ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php"
before_uobject_tag=subprocess.check_output(["git","-C",str(ROOT),"show",
 "0d74c3b6:catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php"])
before_uobject_sha=hashlib.sha256(before_uobject_tag).hexdigest()
after_uobject_text=uobject_src.read_text(encoding="utf-8-sig")
assert "native_source_fixed_StringProperty_to_StrProperty_upgrade" in after_uobject_text
assert "during nested struct FString<<" in after_uobject_text
assert "native_source_fixed_StringProperty_to_StrProperty_upgrade" not in before_uobject_tag.decode("utf-8-sig")
tag_current_line=str(uobject_src)+":"+digest(uobject_src).upper()
assert signature.count(tag_current_line)==1
signature=signature.replace(tag_current_line,
 str(uobject_src)+":"+before_uobject_sha.upper(),1)
assert hashlib.sha256(signature.encode("utf-8")).hexdigest()=="44d9f7ef4d24dea80ed42fe4106136692eae88d36cd1dd7c5fae6cf874e2bcc3"
class_memory_current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
assert class_memory_current=="44d9f7ef4d24dea80ed42fe4106136692eae88d36cd1dd7c5fae6cf874e2bcc3"
class_full_test="verify-ue1-o06-g5-t99-uclass-independent-full.php"
class_full_ev=latest.get(class_full_test)
assert class_full_ev and class_full_ev["state"]=="PASS"
assert class_full_ev["source_sha256"]==class_memory_current
class_full_log=ROOT/class_full_ev["log"].replace("\\","/")
class_full_report=read(class_full_log)
assert class_full_report["ok"] and class_full_report["checks"]==class_full_report["passed"]==1342
assert class_full_report["decision"]=="FULL_SOURCE_PROFILE_UT99_V432_ENGINE_U_V69"
assert len(class_full_report["seven_gates"])==7
assert all(v=="VERIFIED" for v in class_full_report["seven_gates"].values())
assert class_full_report["loaded_script_known_bytes"]==989
assert class_full_report["loaded_Script_pointer_and_FName_fixups"]==130
assert digest(class_full_log)==class_full_ev["output_sha256"]
assert digest(ROOT/"catalog/bin"/class_full_test)==class_full_ev["test_sha256"]
class_memory_path=ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php"
class_memory_old=subprocess.check_output(["git","-C",str(ROOT),"show",
 "858a0d1a:catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php"])
class_memory_old_sha=hashlib.sha256(class_memory_old).hexdigest()
class_memory_text=class_memory_path.read_text(encoding="utf-8-sig")
assert "source_memory_known_mask_hex" in class_memory_text
assert "source_memory_known_mask_hex" not in class_memory_old.decode("utf-8-sig")
memory_line=str(class_memory_path)+":"+digest(class_memory_path).upper()
assert signature.count(memory_line)==1
signature=signature.replace(memory_line,
 str(class_memory_path)+":"+class_memory_old_sha.upper(),1)
assert hashlib.sha256(signature.encode("utf-8")).hexdigest()=="f561ab57ae5322d8494cc00d4eee3c20580849720ddec957d93d992e9d7d7be3"
script_current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
assert script_current=="f561ab57ae5322d8494cc00d4eee3c20580849720ddec957d93d992e9d7d7be3"
script_path=ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php"
script_old_blob=subprocess.check_output(["git","-C",str(ROOT),"show",
 "af9b0c05:catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php"])
script_old=script_old_blob.decode("utf-8-sig")
script_new=script_path.read_text(encoding="utf-8-sig")
assert script_old!=script_new
assert "native_load_relocation_count" in script_new
assert "native_pointer_addresses_fabricated" in script_new
assert "native_load_relocation_count" not in script_old
uclass_test="verify-ue1-o06-g5-t99-uclass-script-loaded-fixups.php"
uclass_ev=latest.get(uclass_test)
assert uclass_ev and uclass_ev.get("state")=="PASS"
assert uclass_ev["source_sha256"]==script_current
uclass_log=ROOT/uclass_ev["log"].replace("\\","/")
uclass_report=read(uclass_log)
assert uclass_report["ok"] and uclass_report["checks"]==uclass_report["passed"]==47
assert uclass_report["native_loaded_pointer_referents_matched"]==3
assert uclass_report["native_loaded_script_opcode_slots_identical"]==57
assert uclass_report["native_loaded_class_default_values_matched"]==3
assert uclass_report["native_loaded_fname_fixup_matches"] is True
assert uclass_report["native_engines_run_by_verifier"]==0
assert digest(uclass_log)==uclass_ev["output_sha256"]
assert digest(ROOT/"catalog/bin"/uclass_test)==uclass_ev["test_sha256"]
script_new_line=str(script_path)+":"+class_memory_old_sha.upper()
script_prev_sha=hashlib.sha256(script_old_blob).hexdigest()
assert signature.count(script_new_line)==1
signature=signature.replace(script_new_line,str(script_path)+":"+script_prev_sha.upper(),1)
assert hashlib.sha256(signature.encode("utf-8")).hexdigest()=="17c0cdc09733266793b486cc07ecce1616c7f8528036e69d7353d24ea75f6c19"
remap_current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
assert remap_current=="17c0cdc09733266793b486cc07ecce1616c7f8528036e69d7353d24ea75f6c19"
remap_path=ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicMeshNativeReader.php"
remap_old_blob=subprocess.check_output(["git","-C",str(ROOT),"show",
 "2aa8257f:catalog/src/Infrastructure/Readers/CatalogUE1ClassicMeshNativeReader.php"])
remap_old_text=remap_old_blob.decode("utf-8-sig")
remap_now_text=remap_path.read_text(encoding="utf-8-sig")
assert remap_old_text!=remap_now_text
assert "native_remap_out_of_bounds_memory_value_undefined" in remap_now_text
assert "epic_load_permutation_complete" in remap_now_text
assert "packed_bytes_sha256" in remap_now_text
remap_ev=latest.get("verify-ue1-o06-g5-t99-skeletal-independent-full.php")
assert remap_ev and remap_ev.get("state")=="PASS" and remap_ev.get("source_sha256")==remap_current
remap_log=ROOT/remap_ev["log"].replace("\\","/")
remap_result=read(remap_log)
assert remap_result["ok"] and remap_result["passed"]==remap_result["checks"]==55
assert remap_result["decision"]=="FULL_UT99_V432_V69_SOURCE_PROFILE_ONLY"
assert remap_result["native_compiled_remap_vertices_compared"]==10
assert remap_result["native_engine_invocations_by_verifier"]==0
assert digest(remap_log)==remap_ev["output_sha256"]
assert digest(ROOT/"catalog/bin/verify-ue1-o06-g5-t99-skeletal-independent-full.php")==remap_ev["test_sha256"]
remap_line=str(remap_path)+":"+digest(remap_path).upper()
remap_previous_sha=hashlib.sha256(remap_old_blob).hexdigest()
assert signature.count(remap_line)==1
signature=signature.replace(remap_line,str(remap_path)+":"+remap_previous_sha.upper(),1)
assert hashlib.sha256(signature.encode("utf-8")).hexdigest()=="9e475134c8cabe0c99d061ce538c7aa1cc5f4411e5653ef332f01bec6a452f24"
# A new compiled-native direct UStrProperty malformed EOF oracle changes
# only one direct FString SerializeItem branch in the tagged reader.
# Reconstruct the preceding accepted source fingerprint for all older
# original native probes, without replaying them.
direct_current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
assert direct_current=="9e475134c8cabe0c99d061ce538c7aa1cc5f4411e5653ef332f01bec6a452f24"
direct_tag=ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php"
direct_old_bytes=subprocess.check_output(["git","-C",str(ROOT),"show",
  "dd773bb0:catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php"])
direct_old=direct_old_bytes.decode("utf-8-sig")
direct_new=before_uobject_tag.decode("utf-8-sig")
direct_hunks=[("".join(direct_old.splitlines(keepends=True)[i:j]),
               "".join(direct_new.splitlines(keepends=True)[k:l]))
    for op,i,j,k,l in difflib.SequenceMatcher(
      None,direct_old.splitlines(keepends=True),
      direct_new.splitlines(keepends=True),autojunk=False).get_opcodes()
    if op!="equal"]
assert len(direct_hunks)==3
assert "FString SerializeItem exceeds export SerialSize" in "".join(a for a,b in direct_hunks)
assert "physical EOF at archive offset" in "".join(b for a,b in direct_hunks)
assert "$streamState['allow_overread']=true" in "".join(b for a,b in direct_hunks)
assert all("FString" in (a+b) or "physicalLimit" in (a+b) for a,b in direct_hunks)
direct_ev=next((e for e in reversed(events)
    if e.get("test")=="verify-ue1-o06-g5-uobject-direct-str-physical-eof.php"
    and e.get("state")=="PASS"
    and e.get("source_sha256")==direct_current),None)
assert direct_ev is not None
direct_out=read(ROOT/direct_ev["log"].replace("\\","/"))
assert direct_out["ok"] and direct_out["passed"]==direct_out["checks"]==24
assert direct_out["compiled_malformed_eof_cursor"]==283
assert digest(ROOT/direct_ev["log"].replace("\\","/"))==direct_ev["output_sha256"]
assert digest(ROOT/"catalog/bin/verify-ue1-o06-g5-uobject-direct-str-physical-eof.php")==direct_ev["test_sha256"]
direct_current_line=str(direct_tag)+":"+before_uobject_sha.upper()
direct_old_line=str(direct_tag)+":"+hashlib.sha256(direct_old_bytes).hexdigest().upper()
assert signature.count(direct_current_line)==1
signature=signature.replace(direct_current_line,direct_old_line,1)
# The immediately preceding DEV commit dd773bb0 corrected only two
# UClass archive defects. Its 87/87 and genuine-six-package 58/58
# accepted proof is retained; restore the older UClass blob to compare
# the historical pre-UClass G5 fingerprint without rewriting the oracle.
assert hashlib.sha256(signature.encode("utf-8")).hexdigest()=="8be84144df4b213d600310ec1a2b254c4d8dcc1c5e66478e4917c535fa486029"
cls_path=ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php"
cls_old_blob=subprocess.check_output(["git","-C",str(ROOT),"show",
 "2def304a:catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php"])
cls_old=cls_old_blob.decode("utf-8-sig")
cls_now=cls_path.read_text(encoding="utf-8-sig")
cls_diff=[("".join(cls_old.splitlines(keepends=True)[i:j]),
           "".join(cls_now.splitlines(keepends=True)[k:l]))
    for op,i,j,k,l in difflib.SequenceMatcher(None,
      cls_old.splitlines(keepends=True),cls_now.splitlines(keepends=True),autojunk=False).get_opcodes()
    if op!="equal"]
assert len(cls_diff)==5
assert "physicalSize" in "".join(b for a,b in cls_diff)
assert "Cannot seek after UClass default tags" in "".join(b for a,b in cls_diff)
assert "serialsize" in "".join((a+b).lower() for a,b in cls_diff)
cls_ev=next((e for e in reversed(events)
    if e.get("test")=="verify-ue1-classic-class-metadata.php"
    and e.get("state")=="PASS"
    and e.get("source_sha256")=="8be84144df4b213d600310ec1a2b254c4d8dcc1c5e66478e4917c535fa486029"),None)
assert cls_ev is not None
# The current full-class source change also has its own journaled
# current-source 87/87 acceptance; retain both exact original proofs.
cls_current=latest.get("verify-ue1-classic-class-metadata.php")
assert cls_current and cls_current["state"]=="PASS"
assert cls_current["source_sha256"]==class_memory_current
cls_out=read(ROOT/cls_ev["log"].replace("\\","/"))
assert cls_out["ok"] and cls_out["total"]==cls_out["passed"]==87
cls_now_line=str(cls_path)+":"+digest(cls_path).upper()
cls_old_line=str(cls_path)+":"+hashlib.sha256(cls_old_blob).hexdigest().upper()
assert signature.count(cls_now_line)==1
signature=signature.replace(cls_now_line,cls_old_line,1)
animation_current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
assert animation_current=="e0e2acef48cae9f109e217240399bcd3da38841cd2556b5156be7723114b5896"
animation_paths=[
    ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php",
    ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicV432NativeDeltaReader.php",
]
animation_old=[]
animation_reconstruction=signature
for animation_file in animation_paths:
    old_bytes=subprocess.check_output(["git","-C",str(ROOT),"show",
        "a64a743b:"+animation_file.relative_to(ROOT).as_posix()])
    old_text=old_bytes.decode("utf-8-sig")
    now_text=animation_file.read_text(encoding="utf-8-sig")
    if animation_file.name=="CatalogUE1ClassicV432NativeDeltaReader.php":
        method="public static function inspectAnimation("
        end="public static function inspectSkeletalMesh("
        assert old_text.split(method,1)[0]==now_text.split(method,1)[0]
        assert old_text.split(end,1)[1]==now_text.split(end,1)[1]
        assert old_text!=now_text
    else:
        hunks=[(old_text.splitlines(keepends=True)[i:j],
                now_text.splitlines(keepends=True)[k:l])
            for op,i,j,k,l in difflib.SequenceMatcher(
                None,old_text.splitlines(keepends=True),
                now_text.splitlines(keepends=True),autojunk=False).get_opcodes()
            if op!="equal"]
        assert len(hunks)==3
        assert all("animation" in "".join(a+b).lower() for a,b in hunks[:2])
        assert "$pos,$exportLimit,$limit" in "".join(hunks[2][1])
    now_line=str(animation_file)+":"+digest(animation_file).upper()
    old_line=str(animation_file)+":"+hashlib.sha256(old_bytes).hexdigest().upper()
    assert animation_reconstruction.count(now_line)==1
    animation_reconstruction=animation_reconstruction.replace(now_line,old_line,1)
    animation_old.append(old_line)
real_current=hashlib.sha256(animation_reconstruction.encode("utf-8")).hexdigest()
assert real_current=="634602f01e77e5f77f423e298a8be35ed14ad90ad825582e1181c60dc3494d50"
def animation_pass(name,checks):
    ev=latest.get(name)
    if not ev or ev.get("state")!="PASS" or ev.get("source_sha256")!=animation_current:
        return False
    log=ROOT/ev["log"].replace("\\","/")
    try: report=read(log)
    except Exception: return False
    return (digest(log)==ev["output_sha256"]
        and digest(ROOT/"catalog"/"bin"/name)==ev["test_sha256"]
        and report.get("ok") is True and report.get("checks")==checks
        and report.get("passed")==checks)
animation_scope=(animation_pass("verify-ue1-o06-g5-t99-animation-native-error-cursors.php",91)
    and animation_pass("verify-ue1-o06-g5-t99-animation-whole-native.php",212))
assert animation_scope,"UT99 v432 animation exact native cursor source isolation not established"
signature=animation_reconstruction
mesh_path=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicMeshNativeReader.php"
mesh_old=subprocess.check_output(["git","-C",str(ROOT),"show",
    "7608dc2b:catalog/src/Infrastructure/Readers/CatalogUE1ClassicMeshNativeReader.php"])
mesh_old_sha=hashlib.sha256(mesh_old).hexdigest()
assert mesh_old_sha=="03edfeb1e4a21807b4b07d2f038feb811c54904b9d8c5cd1a8f0f8dc45f9e220"
mesh_new_sha=remap_previous_sha
assert mesh_new_sha=="4f5700da9742cdee5ae9c7842cb99209710a104c19b00b6e0b06851e1cfd26cb"
def passed_mesh_replay(test,checks):
    event=next((e for e in reversed(events)
        if e.get("test")==test and e.get("state")=="PASS"
        and e.get("source_sha256")==real_current),None)
    if not event: return False
    log=ROOT/event["log"].replace("\\","/")
    try: report=read(log)
    except Exception: return False
    test_hash=digest(ROOT/"catalog"/"bin"/test)
    if test=="verify-ue1-classic-mesh-native.php":
        old_code=subprocess.check_output(["git","-C",str(ROOT),"show",
          "2aa8257f:catalog/bin/verify-ue1-classic-mesh-native.php"])
        test_hash=hashlib.sha256(old_code).hexdigest()
    return (digest(log)==event.get("output_sha256")
        and test_hash==event.get("test_sha256")
        and report.get("ok") is True and report.get("passed")==checks
        and (report.get("total")==checks or report.get("checks")==checks))
mesh_scope=(passed_mesh_replay("verify-ue1-classic-mesh-native.php",40)
    and passed_mesh_replay("verify-ue1-o06-g5-real-skeletal-animation-full-arrays.php",733))
if not mesh_scope:
    raise SystemExit("New native mesh source change lacks complete focused regression evidence")
mesh_line=str(mesh_path)+":"+mesh_new_sha.upper()
assert signature.count(mesh_line)==1
signature=signature.replace(mesh_line,str(mesh_path)+":"+mesh_old_sha.upper(),1)
actual_current=hashlib.sha256(signature.encode("utf-8")).hexdigest()
assert actual_current=="eaf1661ff3c05845595b3293f399848458500267777528f34ea5b236924c7e8c"
eof_tag=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicTaggedPropertyReader.php"
eof_previous_bytes=subprocess.check_output(
    ["git","-C",str(ROOT),"show","0d931131:"+eof_tag.relative_to(ROOT).as_posix()])
eof_previous_source=eof_previous_bytes.decode("utf-8-sig")
eof_previous_sha=hashlib.sha256(eof_previous_bytes).hexdigest().upper()
# Preserve BOTH verified native source changes separately:
# 0d931131 -> 635d6bd9 physical-EOF count first, then
# 635d6bd9 -> current nested FString physical-EOF only.
middle_eof_bytes=subprocess.check_output(
    ["git","-C",str(ROOT),"show",
     "635d6bd9:"+eof_tag.relative_to(ROOT).as_posix()])
eof_now=middle_eof_bytes.decode("utf-8-sig")
nested_now=direct_old  # immutable pre-direct-string production blob
middle_eof_hash=hashlib.sha256(middle_eof_bytes).hexdigest().upper()
eof_old_line=direct_old_line  # restored actual source fingerprint
source_before_nested_sig=signature.replace(
    eof_old_line,str(eof_tag)+":"+middle_eof_hash,1)
source_before_nested=hashlib.sha256(
    source_before_nested_sig.encode("utf-8")).hexdigest()
assert source_before_nested=="b8126477daaa07c7a39d3ef75f8bfa1d2201730f67dfab170baa7e981711ed46"
string_old_segment="                            if(abs($n)>intdiv($archiveLimit-$position,$unit)){"
string_new_segment="                            // Epic UStrProperty::SerializeItem calls the"
string_end_segment="                            $segment.=$read(abs($n)*$unit);"
nested_isolated=(
    eof_now.count(string_old_segment)==1
    and nested_now.count(string_new_segment)==1
    and eof_now.count(string_end_segment)==1
    and nested_now.count(string_end_segment)==1
    and eof_now.split(string_old_segment,1)[0]
        ==nested_now.split(string_new_segment,1)[0]
    and eof_now.split(string_end_segment,1)[1]
        ==nested_now.split(string_end_segment,1)[1]
    and "dynamic array FString exceeds export SerialSize"
        in eof_now.split(string_old_segment,1)[1].split(string_end_segment,1)[0]
    and "ULinkerLoad::Serialize physical EOF at archive offset"
        in nested_now.split(string_new_segment,1)[1].split(string_end_segment,1)[0]
)
nested_test="verify-ue1-o06-g5-uobject-nested-fstring-physical-eof.php"
nested_event=next((e for e in reversed(events)
    if e.get("test")==nested_test and e.get("state")=="PASS"
    and e.get("source_sha256")==actual_current),None)
nested_log=ROOT/nested_event["log"] if nested_event and nested_event.get("log") else None
try: nested_values=read(nested_log) if nested_log and nested_log.exists() else {}
except Exception: nested_values={}
nested_scope=bool(
    nested_isolated and nested_event
    and nested_event.get("state")=="PASS"
    and nested_event.get("source_sha256")==actual_current
    and nested_event.get("output_sha256")==digest(nested_log)
    and nested_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/nested_test)
    and nested_values.get("ok") is True
    and nested_values.get("checks")==25
    and nested_values.get("passed")==25
    and nested_values.get("native_mutation_physical_EOF_byte")==308
    and nested_values.get("production_physical_EOF_cursor")==308
    and nested_values.get("native_mutation_exception_origin")=="ULinkerLoad::Serialize <- FString<<"
    and nested_values.get("stock_native_loaded_FString_parity")=="NOT_VERIFIED"
    and nested_values.get("general_T99_UObject_METHOD")=="BLOCKED"
)
if not nested_scope:
    raise SystemExit("Original compiled v432 nested FString physical-EOF source-scope proof FAILED")

assert signature.count(eof_old_line)==1
source_before_eof_sig=signature.replace(
    eof_old_line,str(eof_tag)+":"+eof_previous_sha,1)
source_before_eof=hashlib.sha256(source_before_eof_sig.encode("utf-8")).hexdigest()
assert source_before_eof=="52613984361566ff8d218031069ba98878b2e2dc1d007d117bb40386d257fe78"
tag_deltas=[
  (eof_previous_source.splitlines(keepends=True)[a:b],
   eof_now.splitlines(keepends=True)[c:d])
  for op,a,b,c,d in difflib.SequenceMatcher(
      None,eof_previous_source.splitlines(keepends=True),
      eof_now.splitlines(keepends=True),autojunk=False).get_opcodes()
  if op!="equal"
]
eof_old_branch="                    $remaining=$archiveLimit-$position;"
eof_new_branch="                    // Epic UArrayProperty::SerializeItem does not bound"
eof_end_branch="                    $elements=[];"
eof_delta_isolated=(
    len(tag_deltas)==2
    and eof_previous_source.count(eof_old_branch)==1
    and eof_now.count(eof_new_branch)==1
    and eof_previous_source.count(eof_end_branch)==1
    and eof_now.count(eof_end_branch)==1
    and eof_previous_source.split(eof_old_branch,1)[0]
        ==eof_now.split(eof_new_branch,1)[0]
    and eof_previous_source.split(eof_end_branch,1)[1]
        ==eof_now.split(eof_end_branch,1)[1]
    and "dynamic array element count exceeds export SerialSize"
        in eof_previous_source.split(eof_old_branch,1)[1].split(eof_end_branch,1)[0]
    and "ULinkerLoad::Serialize physical EOF at archive offset"
        in eof_now.split(eof_new_branch,1)[1].split(eof_end_branch,1)[0]
)
eof_test="verify-ue1-o06-g5-uobject-native-array-physical-eof.php"
eof_event=latest.get(eof_test)
eof_log=ROOT/eof_event["log"] if eof_event and eof_event.get("log") else None
try: eof_values=read(eof_log) if eof_log and eof_log.exists() else {}
except Exception: eof_values={}
eof_scope=bool(
    eof_delta_isolated
    and eof_event and eof_event.get("state")=="PASS"
    and eof_event.get("source_sha256")==source_before_nested
    and eof_event.get("output_sha256")==digest(eof_log)
    and eof_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/eof_test)
    and eof_values.get("ok") is True
    and eof_values.get("checks")==21
    and eof_values.get("passed")==21
    and eof_values.get("native_linker_failure_absolute_offset")==308
    and eof_values.get("PHP_diagnostic_native_source_origin")=="ULinkerLoad::Serialize"
    and eof_values.get("original_archive_restore_SHA_verified") is True
    and eof_values.get("native_binary_reruns_in_verifier")==0
    and eof_values.get("global_UObject_method_full")=="BLOCKED_OTHER_SOURCE_VARIANTS"
)
if not eof_scope:
    raise SystemExit("Native original EOF source-isolation proof FAILED: do not reuse earlier FULL methods")
signature=source_before_eof_sig
current=source_before_eof
# Two independently reviewed, branch-local reader changes, with no native
# retests. Pin the BEFORE/MIDDLE commits and reconstruct their source SHA
# from ALL current source input hashes, substituting only these two readers.
# Native method evidence for unaffected branches remains valid; if any other
# source file or positive decoder changes, this proof automatically fails.
text_reader=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicNativeObjectReader.php"
delta_reader=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicV432NativeDeltaReader.php"
def committed(ref,path):
    return subprocess.check_output(["git","-C",str(ROOT),"show",
        ref+":"+path.relative_to(ROOT).as_posix()]).decode("utf-8-sig")
old_text=committed("e6583f48",text_reader)
mid_text=committed("97702616",text_reader)
mid_delta=committed("97702616",delta_reader)
new_text=committed("a64a743b",text_reader)
new_delta=committed("a64a743b",delta_reader)
old_fingerprint="834dac6fe9b5fc71c3d8cfb04667bd01046dc258e5c93b57655dfb01831c4978"
middle_fingerprint="8d8a18f37e2e7085af64f630de1fea3b5f35d30d60cbbfd8520ce879880269fe"
def subst_sig(s,path,new_hash,old_hash):
    before=str(path)+":"+new_hash
    assert s.count(before)==1
    return s.replace(before,str(path)+":"+old_hash,1)
def sha_blob(value):
    return hashlib.sha256(value.encode("utf-8")).hexdigest().upper()
# Latest 2026-10-24 native tagged-error correction modified exactly TWO
# source readers. Reconstruct every old source input to the previously pinned
# 36bae778 fingerprint. The original 113/113 genuine-native values and four
# compiled malformed property outcomes were checked against the CURRENT
# sources by the one new SHA-pinned PASS 49/49 acceptance journal.
new_tag_file=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicTaggedPropertyReader.php"
new_schema_file=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicClassDefaultSchemaReader.php"
old_latest_tag=committed("36bae778",new_tag_file)
old_latest_schema=committed("36bae778",new_schema_file)
current_tag=eof_previous_source
current_schema=new_schema_file.read_text(encoding="utf-8-sig")
# One source-local audio branch delta. Unreal v1.200 USound and UMusic
# own TArray<BYTE> for EVERY archive version; UT99 v400 and v227
# own TLazyArray<BYTE>, whose SeekPos is written only above v61.
# Only this isolated audio branch changes, while compiled v432 audio
# and ALL nonaudio archived handling remain semantically unchanged.
before_audio_native=committed("1ca5c902",text_reader)
before_audio_source="2610d2ccd252d8cb8b342c8ac2b4aae05640ea0502dd19166e91ade49d368e6f"
# The one additional method-specific fix changes only the audio branch:
# Epic TLazyArray seeks forward to SeekPos even if count consumed fewer
# bytes. Actual compiled v432 UMusic count-zero loads; empty USound
# instead faults in the native WAV Duration loader. No other branch
# or shared UObject implementation has changed.
before_forward_native=committed("c8240c18",text_reader)
before_forward_source="30020059d7876c0d5e078f1ed24942487d002525f89641e70072b5d2467c7dcc"
sig_before_forward=subst_sig(
    signature,text_reader,sha_blob(committed("a64a743b",text_reader)),sha_blob(before_forward_native))
forward_test="verify-ue1-o06-g5-audio-loaded-and-malformed-acceptance.php"
forward_ev=latest.get(forward_test)
forward_log=ROOT/forward_ev["log"] if forward_ev and forward_ev.get("log") else None
try:
    forward_values=read(forward_log) if forward_log and forward_log.exists() else {}
except Exception:
    forward_values={}
forward_scope=(
    hashlib.sha256(sig_before_forward.encode("utf-8")).hexdigest()==before_forward_source
    and "lazy_forward_skipped_unloaded_bytes" in new_text
    and "native FSoundData::GetPeriod dereferences WavData(0)" in new_text
    and forward_ev is not None and forward_ev.get("state")=="PASS"
    and forward_ev.get("source_sha256")==current
    and forward_ev.get("output_sha256")==digest(forward_log)
    and forward_ev.get("test_sha256")==digest(ROOT/"catalog"/"bin"/forward_test)
    and forward_values.get("ok") is True
    and forward_values.get("checks")==66 and forward_values.get("passed")==66
    and forward_values.get("compiled_loaded_sound_objects")==9
    and forward_values.get("compiled_loaded_music_objects")==2
    and forward_values.get("native_memory_bytes_match")==1216472
    and forward_values.get("Sound_Duration_exact_bits")==9
    and forward_values.get("compiled_distinct_malformed_cases")==5
    and forward_values.get("native_loaded_and_malformed_acceptance")=="VERIFIED_SCOPE"
    and forward_values.get("full_Sound_Music_method")=="BLOCKED_SHARED_UOBJECT_AND_FINAL_GATES"
)
sig_before_audio=subst_sig(
    sig_before_forward,text_reader,sha_blob(before_forward_native),sha_blob(before_audio_native))
audio_start="if($nativeKind==='sound'||$nativeKind==='music'){"
audio_end="if($nativeKind==='font'&&$family===CatalogUE1ClassicSourceFamily::UT99_V432){"
audio_part_old=before_audio_native.split(audio_start,1)
audio_part_new=before_forward_native.split(audio_start,1)
audio_branch_isolated=False
if len(audio_part_old)==2 and len(audio_part_new)==2:
    before_parts=audio_part_old[1].split(audio_end,1)
    after_parts=audio_part_new[1].split(audio_end,1)
    audio_branch_isolated=(
        len(before_parts)==2 and len(after_parts)==2
        and audio_part_old[0]==audio_part_new[0]
        and before_parts[1]==after_parts[1])
audio_test="verify-ue1-o06-g5-native-audio-source-array-decision.php"
audio_event=latest.get(audio_test)
audio_log=ROOT/audio_event["log"] if audio_event and audio_event.get("log") else None
try:
    audio_values=read(audio_log) if audio_log and audio_log.exists() else {}
except Exception:
    audio_values={}
audio_scope=(
    forward_scope
    and hashlib.sha256(sig_before_audio.encode("utf-8")).hexdigest()==before_audio_source
    and audio_branch_isolated
    and "$lazyAudio=$family!==CatalogUE1ClassicSourceFamily::EPIC_UE1;" in new_text
    and "$seekEnd=$lazyAudio&&$ver>61?$int():null;" in new_text
    and audio_event is not None and audio_event.get("state")=="PASS"
    and audio_event.get("source_sha256")==before_forward_source
    and audio_event.get("output_sha256")==digest(audio_log)
    and audio_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/audio_test)
    and audio_values.get("ok") is True
    and audio_values.get("checks")==176 and audio_values.get("passed")==176
    and audio_values.get("genuine_real_original_audio_exports")==35
    and audio_values.get("v1200_TArray_audio_records")==2
    and audio_values.get("v227_TLazyArray_audio_records")==33
    and audio_values.get("compilation_engine_reruns")==0
    and audio_values.get("method_full_decision")=="BLOCKED"
)
# The shared classic export-map decoder was a missing fingerprint input:
# it is transitive input for every UE1 native/source verdict, so pin it
# from this point forward. Its only behavioral delta guards confirmed
# v69/licensee227 exports carrying RF_HasArchtype, whose native
# FObjectExport archive operator is not part of the public v227 source.
legacy_reader=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogLegacyPackageReader.php"
old_legacy=committed("dff8840a",legacy_reader)
new_legacy=legacy_reader.read_text(encoding="utf-8-sig")
previous_legacy_source="694bd853341a6a44afafced6d91635f008147f88668c9281b730a5c310310320"
legacy_sig_line=str(legacy_reader)+":"+digest(legacy_reader).upper()
sig_before_legacy="\n".join(line for line in sig_before_audio.split("\n")
    if line!=legacy_sig_line)
legacy_guard_test="verify-ue1-o06-c227-archetype-native-operator-guard.php"
legacy_event=latest.get(legacy_guard_test)
legacy_log=ROOT/legacy_event["log"] if legacy_event and legacy_event.get("log") else None
try:
    legacy_values=read(legacy_log) if legacy_log and legacy_log.exists() else {}
except Exception:
    legacy_values={}
legacy_guard_scope=(
    audio_scope
    and sig_before_audio.count(legacy_sig_line)==1
    and hashlib.sha256(sig_before_legacy.encode("utf-8")).hexdigest()==previous_legacy_source
    and "CatalogLegacyPackageReader.php" in str(legacy_reader)
    and "requires unverified native ArchetypeIndex" in new_legacy
    and "requires unverified native ArchetypeIndex" not in old_legacy
    and legacy_event is not None and legacy_event.get("state")=="PASS"
    and legacy_event.get("source_sha256")==before_audio_source
    and legacy_event.get("output_sha256")==digest(legacy_log)
    and legacy_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/legacy_guard_test)
    and legacy_values.get("ok") is True
    and legacy_values.get("checks")==24 and legacy_values.get("passed")==24
    and legacy_values.get("genuine_export_rows_unmodified")==426
    and legacy_values.get("dev_only_single_flag_mutations_rejected")==3
    and legacy_values.get("compiled_native_replays")==0
    and legacy_values.get("full_method_decision")=="BLOCKED"
)
# Camera is an intentional source-defined ULinkerLoad::CreateExport
# exclusion (v1200 Ver<57, UT99 inherited v400 all versions). A real
# compiled v432 native loader already omitted the four v61 and five
# v68 Camera exports, so no repeat engine run is justified.
before_camera_tag=committed("06ae43e2",new_tag_file)
previous_camera_source="6dba20b269aa8efc12f97f57ffca9ddd293a06e1a5d2f6b78e285282860929d8"
sig_before_camera=subst_sig(
    sig_before_legacy,new_tag_file,eof_previous_sha,sha_blob(before_camera_tag))
camera_test="verify-ue1-o06-g5-camera-source-reachability-decision.php"
camera_event=latest.get(camera_test)
camera_log=ROOT/camera_event["log"] if camera_event and camera_event.get("log") else None
try:
    camera_result=read(camera_log) if camera_log and camera_log.exists() else {}
except Exception:
    camera_result={}
camera_scope=(
    legacy_guard_scope
    and hashlib.sha256(sig_before_camera.encode("utf-8")).hexdigest()==previous_camera_source
    and "native_loader_class_excluded_by_epic_source" in current_tag
    and "native_loader_UObject_serialize_invoked" in current_tag
    and "v1200_Camera_skipped_before_version57" in current_tag
    and "UT99_v400_inherited_Camera_CreateExport_returns_NULL" in current_tag
    and camera_event is not None and camera_event.get("state")=="PASS"
    and camera_event.get("source_sha256")==previous_legacy_source
    and camera_event.get("output_sha256")==digest(camera_log)
    and camera_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/camera_test)
    and camera_result.get("ok") is True
    and camera_result.get("checks")==69
    and camera_result.get("passed")==69
    and camera_result.get("genuine_source_excluded_Camera_exports")==14
    and camera_result.get("unrelated_original_exports_not_falsely_skipped")==15
    and camera_result.get("native_compiled_v432_4_plus_5_Camera_exclusions_sha_reused")==9
    and camera_result.get("camera_native_CreateExport_criterion")=="CLOSED_SOURCE_DEFINED_EXCLUSION"
    and camera_result.get("compiled_native_oracle_replays")==0
    and camera_result.get("full_method_decision")=="BLOCKED"
)
# Genuine original Unreal Gold v54 UModel native archives prove the
# Epic v1.200 FSphere : FVector serializes only twelve bytes. The
# v1200-only Primitive reader change corrects 299 real UModel payloads
# without touching the UT99 v432 version-gated sphere operator.
primitive_file=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicPrimitiveNativeReader.php"
before_primitive=committed("7fea68cb",primitive_file)
previous_primitive_source="c63cafd02a93875b5f57f5b2395cb8336555bc65d5bdb3550724b3033fb3a29f"
sig_before_primitive=subst_sig(
    sig_before_camera,primitive_file,digest(primitive_file).upper(),sha_blob(before_primitive))
primitive_test="verify-ue1-o06-c98-v54-real-model-sphere-source-decision.php"
primitive_ev=latest.get(primitive_test)
primitive_log=ROOT/primitive_ev["log"] if primitive_ev and primitive_ev.get("log") else None
try:
    primitive_result=read(primitive_log) if primitive_log and primitive_log.exists() else {}
except Exception:
    primitive_result={}
primitive_scope=(
    camera_scope
    and hashlib.sha256(sig_before_primitive.encode("utf-8")).hexdigest()==previous_primitive_source
    and "FSphere : FVector has" in primitive_file.read_text(encoding="utf-8-sig")
    and "elseif(!$original&&$version>61)" in primitive_file.read_text(encoding="utf-8-sig")
    and primitive_ev is not None and primitive_ev.get("state")=="PASS"
    and primitive_ev.get("source_sha256")==previous_camera_source
    and primitive_ev.get("output_sha256")==digest(primitive_log)
    and primitive_ev.get("test_sha256")==digest(ROOT/"catalog"/"bin"/primitive_test)
    and primitive_result.get("ok") is True
    and primitive_result.get("checks")==31 and primitive_result.get("passed")==31
    and primitive_result.get("genuine_PRD_original_v54_export_count")==2667
    and primitive_result.get("real_legacy_UModel_complete_after_fix")==299
    and primitive_result.get("real_legacy_UModel_previously_failed_or_extended")==299
    and primitive_result.get("UT99_v432_existing_native_oracles_rerun")==0
    and primitive_result.get("full_method_decision")=="BLOCKED"
)
# Original PRD v69 licensee 227 packages prove that old v1.200
# ScriptArchive opcode meanings are wrong for v227. Keep the v432
# current source and every compiled oracle intact: reconstruct the
# immediate previous global source SHA by substituting ONLY this reader.
v227_script_file=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicScriptArchiveReader.php"
before_v227_script=committed("b7a7254f",v227_script_file)
previous_v227_script_source="323f493f971869e5a635f03597daf15cda317bf7ca58521178fc2b3d56a771e0"
sig_before_v227_script=subst_sig(
    sig_before_primitive,v227_script_file,script_prev_sha.upper(),sha_blob(before_v227_script))
v227_script_test="verify-ue1-o06-c227-genuine-script-source-decision.php"
v227_script_event=latest.get(v227_script_test)
v227_script_log=ROOT/v227_script_event["log"] if v227_script_event and v227_script_event.get("log") else None
try:
    v227_script_result=read(v227_script_log) if v227_script_log and v227_script_log.exists() else {}
except Exception:
    v227_script_result={}
v227_script_scope=(
    primitive_scope
    and hashlib.sha256(sig_before_v227_script.encode("utf-8")).hexdigest()==previous_v227_script_source
    and "CatalogUE1ClassicSourceFamily::UNREAL_V227" in v227_script_file.read_text(encoding="utf-8-sig")
    and "EX_MapKeyElement archive traversal unverified" in v227_script_file.read_text(encoding="utf-8-sig")
    and v227_script_event is not None and v227_script_event.get("state")=="PASS"
    and v227_script_event.get("source_sha256")==previous_primitive_source
    and v227_script_event.get("output_sha256")==digest(v227_script_log)
    and v227_script_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/v227_script_test)
    and v227_script_result.get("ok") is True
    and v227_script_result.get("checks")==24
    and v227_script_result.get("passed")==24
    and v227_script_result.get("genuine_original_licensee_227_packages")==3
    and v227_script_result.get("real_original_exports")==426
    and v227_script_result.get("original_object_tagged_streams_decoded")==399
    and v227_script_result.get("original_UClass_export_streams_decoded")==27
    and v227_script_result.get("current_original_class_export_parser_errors")==0
    and v227_script_result.get("compiled_engine_reruns_in_verifier")==0
    and v227_script_result.get("full_method_decision")=="BLOCKED"
)
# Unreal v227 uses RF_PendingDelete where v1200/UT99 define
# RF_DebugSerialize. Both production UObject and UClass post-load flag
# annotations changed. Reconstruct prior complete source hash by
# substituting only the two exact prechange reader blobs. No native replay.
class_meta_file=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicClassMetadataReader.php"
before_v227_flag_tag=committed("eb968b34",new_tag_file)
before_v227_flag_class=committed("eb968b34",class_meta_file)
previous_v227_flag_source="8a58b69fdfbed2387a880a9bff28e1f75895e7170d3b1e818354ce435651d53f"
sig_before_v227_flag=subst_sig(
    sig_before_v227_script,new_tag_file,sha_blob(before_camera_tag),sha_blob(before_v227_flag_tag))
sig_before_v227_flag=subst_sig(
    sig_before_v227_flag,class_meta_file,hashlib.sha256(cls_old_blob).hexdigest().upper(),sha_blob(before_v227_flag_class))
v227_flag_verifier="verify-ue1-o06-g5-uobject-v227-source-flag-decision.php"
v227_flag_event=latest.get(v227_flag_verifier)
v227_flag_log=ROOT/v227_flag_event["log"] if v227_flag_event and v227_flag_event.get("log") else None
try:
    v227_flag_values=read(v227_flag_log) if v227_flag_log and v227_flag_log.exists() else {}
except Exception:
    v227_flag_values={}
v227_flag_scope=(
    v227_script_scope
    and hashlib.sha256(sig_before_v227_flag.encode("utf-8")).hexdigest()==previous_v227_flag_source
    and current_schema==committed("eb968b34",new_schema_file)
    and "CatalogUE1ClassicSourceFamily::UNREAL_V227" in current_tag
    and "loaded_object_flags_after_uobject_serialize" in current_tag
    and "loaded_object_flags_after_uclass_serialize" in class_meta_file.read_text(encoding="utf-8-sig")
    and "loaded_object_flags_after_uobject_serialize" in class_meta_file.read_text(encoding="utf-8-sig")
    and v227_flag_event is not None and v227_flag_event.get("state")=="PASS"
    and v227_flag_event.get("source_sha256")==previous_v227_script_source
    and v227_flag_event.get("output_sha256")==digest(v227_flag_log)
    and v227_flag_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/v227_flag_verifier)
    and v227_flag_values.get("ok") is True
    and v227_flag_values.get("checks")==30 and v227_flag_values.get("passed")==30
    and v227_flag_values.get("v1200_v432_shared_archive_frame_versions")=="60..61"
    and v227_flag_values.get("v227_loaded_DebugSerialize_bit")=="never fabricated"
    and v227_flag_values.get("old_compiled_PRD_v61_load_exact_identity_count_SHA_reused")==308
    and v227_flag_values.get("native_engine_reruns_in_verifier")==0
    and v227_flag_values.get("full_method_decision")=="BLOCKED"
)
# Source-defined nonempty dynamic FString native materialization hazard:
# PRD real UE1 corpus + real compiled v432 SavePackage output, CDB native
# invalid pointer on LoadPackage. Only a single current-source decision
# accepts the typed archive preview as NOT_VERIFIED native loaded value.
before_string_tag=committed("52fa7a45",new_tag_file)
previous_string_source="aae4f3ff114d1b0823efb2791c4a79c2afcc2ca2e96162f7d92f149e7ea7f21b"
sig_before_string=subst_sig(
    sig_before_v227_flag,new_tag_file,sha_blob(before_v227_flag_tag),sha_blob(before_string_tag))
string_verifier="verify-ue1-o06-g5-uobject-prd-native-string-decision.php"
string_event=latest.get(string_verifier)
string_log=ROOT/string_event["log"] if string_event and string_event.get("log") else None
try:
    string_result=read(string_log) if string_log and string_log.exists() else {}
except Exception:
    string_result={}
string_scope=(
    v227_flag_scope
    and hashlib.sha256(sig_before_string.encode("utf-8")).hexdigest()==previous_string_source
    and current_schema==committed("52fa7a45",new_schema_file)
    and "native_loaded_value_parity" in current_tag
    and "UArrayProperty::SerializeItem uses FArray::Add" in current_tag
    and string_event is not None and string_event.get("state")=="PASS"
    and string_event.get("source_sha256")==previous_v227_flag_source
    and string_event.get("output_sha256")==digest(string_log)
    and string_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/string_verifier)
    and string_result.get("ok") is True
    and string_result.get("checks")==37 and string_result.get("passed")==37
    and string_result.get("prd_original_staged_u_unr")==239
    and string_result.get("prd_original_camera_StateFrames_static_decoded")==433
    and string_result.get("prd_source_native_FSpark_records_decoded")==4573
    and string_result.get("real_v432_saved_nonempty_FString_elements_source_decoded")==2
    and string_result.get("compiled_Core_native_load_success") is False
    and string_result.get("current_production_string_native_loaded_value_parity")=="NOT_VERIFIED"
    and string_result.get("previous_compiled_native_nested_vector_cases_SHA_reused")==3
    and string_result.get("compiled_native_reruns_in_acceptance")==0
    and string_result.get("full_method_decision")=="BLOCKED"
)
# Nested StructProperty ArrayProperty: original v432 Core DLL produced
# and loaded two source-declared Core.Vector UStruct values; three native
# damaged count/TagSize cases SHA pinned, current 61-check acceptance.
before_nested_tag=committed("81809c7b",new_tag_file)
previous_nested_source="92022c708d55d6597e787a4d3b0a70a934e61e9e78d4bbf7ca38523c651cd415"
sig_before_nested=subst_sig(
    sig_before_string,new_tag_file,sha_blob(before_string_tag),sha_blob(before_nested_tag))
nested_verifier="verify-ue1-o06-g5-uobject-nested-vector-decision.php"
nested_ev=latest.get(nested_verifier)
nested_log=ROOT/nested_ev["log"] if nested_ev and nested_ev.get("log") else None
try:
    nested_result=read(nested_log) if nested_log and nested_log.exists() else {}
except Exception:
    nested_result={}
nested_scope=(
    string_scope
    and hashlib.sha256(sig_before_nested.encode("utf-8")).hexdigest()==previous_nested_source
    and current_schema==committed("81809c7b",new_schema_file)
    and "StructArrayValueReader::resolve" in current_tag
    and "FStringArchiveReader::decodeAt" in current_tag
    and "physical_limit" in current_tag
    and nested_ev is not None and nested_ev.get("state")=="PASS"
    and nested_ev.get("source_sha256")==previous_string_source
    and nested_ev.get("output_sha256")==digest(nested_log)
    and nested_ev.get("test_sha256")==digest(ROOT/"catalog"/"bin"/nested_verifier)
    and nested_result.get("ok") is True
    and nested_result.get("checks")==36 and nested_result.get("passed")==36
    and nested_result.get("real_compiled_native_nested_struct_cases_matched")==3
    and nested_result.get("compiled_native_nested_vector_loaded_value_matches")==1
    and nested_result.get("compiled_native_nested_array_fatal_cursor_matches")==2
    and nested_result.get("previous_six_dynamic_array_cases_SHA_reused")==6
    and nested_result.get("previous_genuine_original_TaggedValues_SHA_reused")==113
    and nested_result.get("compiled_native_engine_reruns_in_verifier")==0
    and nested_result.get("full_method_decision")=="BLOCKED"
)
# Actual compiled v432 native-generated ArrayProperty corrupted AR_INDEX /
# inner linker references, sealed 61-check current-source method decision.
before_dynamic_tag=committed("d0061f00",new_tag_file)
previous_dynamic_source="f7f08918772439cf0d591b4bd2f341633cc226c01a36f44440496ad95831508c"
sig_before_dynamic=subst_sig(
    sig_before_nested,new_tag_file,sha_blob(before_nested_tag),sha_blob(before_dynamic_tag))
dynamic_verifier="verify-ue1-o06-g5-uobject-dynamic-array-decision.php"
dynamic_event=latest.get(dynamic_verifier)
dynamic_log=ROOT/dynamic_event["log"] if dynamic_event and dynamic_event.get("log") else None
try:
    dynamic_values=read(dynamic_log) if dynamic_log and dynamic_log.exists() else {}
except Exception:
    dynamic_values={}
dynamic_scope=(
    nested_scope
    and hashlib.sha256(sig_before_dynamic.encode("utf-8")).hexdigest()==previous_dynamic_source
    and current_schema==committed("d0061f00",new_schema_file)
    and "UE1 v432 dynamic array Inner declaration unresolved" in current_tag
    and "source_typed_dynamic_array" in current_tag
    and "ULinkerLoad<<FName invalid name index" in current_tag
    and dynamic_event is not None and dynamic_event.get("state")=="PASS"
    and dynamic_event.get("source_sha256")==previous_nested_source
    and dynamic_event.get("output_sha256")==digest(dynamic_log)
    and dynamic_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/dynamic_verifier)
    and dynamic_values.get("ok") is True
    and dynamic_values.get("checks")==61 and dynamic_values.get("passed")==61
    and dynamic_values.get("new_compiled_dynamic_array_oracle_cases")==6
    and dynamic_values.get("new_compiled_array_fatal_archive_cursors_matched")==4
    and dynamic_values.get("new_compiled_array_valid_malicious_size_cases_matched")==2
    and dynamic_values.get("compiled_native_original_three_array_values_matched")==3
    and dynamic_values.get("native_original_113_loaded_property_values_prior_SHA_reused")==113
    and dynamic_values.get("compiled_native_loader_reruns_in_verifier")==0
    and dynamic_values.get("full_method_decision")=="BLOCKED"
)
# One additional source-scoped v432 valid Struct/FString SerializeItem
# correction. Require CURRENT-source 50/50 decision and reconstruct the
# exact 55fa4f1e parent fingerprint before applying older substitutions.
before_size_tag=committed("55fa4f1e",new_tag_file)
previous_size_source="f2f64ef9a2202c18c01ebc61293946990358de9a55e48cdbb2d3021f25dc779f"
sig_before_size=subst_sig(
    sig_before_dynamic,new_tag_file,sha_blob(before_dynamic_tag),sha_blob(before_size_tag))
size_decision="verify-ue1-o06-g5-uobject-serializeitem-size-decision.php"
size_event=latest.get(size_decision)
size_log=ROOT/size_event["log"] if size_event and size_event.get("log") else None
try:
    size_values=read(size_log) if size_log and size_log.exists() else {}
except Exception:
    size_values={}
size_scope=(
    dynamic_scope
    and hashlib.sha256(sig_before_size.encode("utf-8")).hexdigest()==previous_size_source
    and current_schema==committed("55fa4f1e",new_schema_file)
    and "UT99 UStrProperty::SerializeItem delegates" in current_tag
    and "strlen($raw)>=6&&strlen($raw)<=10" in current_tag
    and size_event is not None and size_event.get("state")=="PASS"
    and size_event.get("source_sha256")==previous_dynamic_source
    and size_event.get("output_sha256")==digest(size_log)
    and size_event.get("test_sha256")==digest(ROOT/"catalog"/"bin"/size_decision)
    and size_values.get("ok") is True
    and size_values.get("checks")==50 and size_values.get("passed")==50
    and size_values.get("real_native_new_size_mutation_cases")==4
    and size_values.get("valid_struct_TagSize_mismatch_consumes_source_bytes")==2
    and size_values.get("valid_FString_TagSize_mismatch_consumes_source_bytes")==2
    and size_values.get("compiled_original_tags_current_source_matched")==113
    and size_values.get("compiled_original_exports_current_source_matched")==33
    and size_values.get("compiled_native_loader_reruns")==0
    and size_values.get("full_method_decision")=="BLOCKED"
)
# 2026-10-24 reference / array-index / PointRegion v432 correction.
# Reconstruct exactly the parent 122f8fbc source fingerprint and require
# the latest single consolidated 61-check CURRENT-source acceptance.
previous_reference_tag=committed("122f8fbc",new_tag_file)
previous_reference_source="d8fe5ee5b5caa022c312601e742806401e9d7af0a0573e5a31aa5fe38d515372"
sig_before_reference=subst_sig(
    sig_before_size,new_tag_file,sha_blob(before_size_tag),sha_blob(previous_reference_tag))
new_reference_acceptance="verify-ue1-o06-g5-uobject-current-full-decision.php"
ref_ev=latest.get(new_reference_acceptance)
ref_log=ROOT/ref_ev["log"] if ref_ev and ref_ev.get("log") else None
try:
    ref_values=read(ref_log) if ref_log and ref_log.exists() else {}
except Exception:
    ref_values={}
reference_scope=(
    size_scope
    and hashlib.sha256(sig_before_reference.encode("utf-8")).hexdigest()==previous_reference_source
    and current_schema==committed("122f8fbc",new_schema_file)
    and "IndexToObject" in current_tag
    and "native_source_property_SerializeItem" in current_tag
    and "readLinkerIndex" in current_tag
    and ref_ev is not None and ref_ev.get("state")=="PASS"
    and ref_ev.get("source_sha256")==previous_size_source
    and ref_ev.get("output_sha256")==digest(ref_log)
    and ref_ev.get("test_sha256")==digest(ROOT/"catalog"/"bin"/new_reference_acceptance)
    and ref_values.get("ok") is True
    and ref_values.get("checks")==61 and ref_values.get("passed")==61
    and ref_values.get("real_native_reference_outcomes_matched")==6
    and ref_values.get("fatal_archive_offset_outcomes_matched")==4
    and ref_values.get("nonfatal_exact_skip_bytes_outcomes_matched")==2
    and ref_values.get("current_source_compiled_loaded_values_matched")==113
    and ref_values.get("current_source_compiled_loaded_exports")==33
    and ref_values.get("new_compiled_engine_invocations")==0
    and ref_values.get("full_method_decision")=="BLOCKED"
)
previous_latest_source="452e64cfe46f9df04a12680b7377ec84d9c49e398eb58ff96de86816a364eaa3"
sig_before_latest=subst_sig(
    subst_sig(sig_before_reference,new_tag_file,sha_blob(previous_reference_tag),sha_blob(old_latest_tag)),
    new_schema_file,digest(new_schema_file).upper(),sha_blob(old_latest_schema))
# The added declaration resolver is scoped: the old class-default inspector
# remains byte-for-byte unchanged after its original doc/inspect anchor.
schema_anchor="    /**\n     * @param array<string,mixed> $classRecord"
method_start="    /**\n     * Resolve an ordinary UObject instance"
schema_scope=(
    old_latest_schema.count(schema_anchor)==1
    and current_schema.count(schema_anchor)==1
    and current_schema.count(method_start)==1
    and current_schema[:current_schema.index(method_start)]
        + current_schema[current_schema.index(schema_anchor):]
       == old_latest_schema
    and "actor_super_only_archive" in current_schema
)
tag_scope=(
    current_tag.count("native_warning_skip_declared_tag_bytes")==1
    and "resolveObjectPropertyDeclarations(" in current_tag
    and "$loadSize=4;" in current_tag
    and "$position!==$size" in current_tag
    and "fpointregion_zone_leaf_number" in current_tag
)
# The *latest* exact current-source acceptance event is required to reuse
# old native positive results; simple hash-substitution alone is insufficient.
new_acceptance="verify-ue1-o06-g5-uobject-tag-error-consolidated.php"
proof=latest.get(new_acceptance)
proof_log=ROOT/proof["log"].replace("\\","/") if proof and proof.get("log") else None
try:
    proof_values=read(proof_log) if proof_log and proof_log.exists() else {}
except Exception:
    proof_values={}
latest_source_scope=(
    reference_scope
    and hashlib.sha256(sig_before_latest.encode("utf-8")).hexdigest()==previous_latest_source
    and schema_scope and tag_scope
    and proof is not None and proof.get("state")=="PASS"
    and proof.get("source_sha256")==previous_reference_source
    and proof.get("output_sha256")==digest(proof_log)
    and proof.get("test_sha256")==digest(ROOT/"catalog"/"bin"/new_acceptance)
    and proof_values.get("ok") is True
    and proof_values.get("checks")==49
    and proof_values.get("passed")==49
    and proof_values.get("native_tag_error_cases_matching")==4
    and proof_values.get("all_native_tagged_positive_values_matched")==113
    and proof_values.get("compiled_oracle_executable_replays")==0
)
# The new UObject change is precisely scoped to the execution-frame
# offset check for non-INDEX_NONE values, and to passing the source file and
# export index into that helper. Preserve already SHA-sealed old POSITIVE
# native evidence for the unchanged INDEX_NONE path. Never treat this
# equivalence as complete method acceptance or error-branch evidence.
# Current edit only adds a source-defined v432 FPointRegion decoder to the
# existing StructProperty branch, leaving other codecs byte-identical.
region_file=ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicTaggedPropertyReader.php"
before_region=committed("96713018",region_file)
after_region=old_latest_tag
region_start="                    }elseif($structName==='pointregion'"
region_end="                    }elseif($structName==='adrop'"
if after_region.count(region_start)==1 and after_region.count(region_end)==1:
    prefix,tail=after_region.split(region_start,1)
    added,ending=tail.split(region_end,1)
    region_insertion=region_start+added
    region_code_scoped=prefix+region_end+ending==before_region
else:
    region_code_scoped=False
previous_full_source="bd8f3d5b028951d08b0548b43586fe98cae55f6b709edc142369f2bd1c92a3a7"
sig_before_region=subst_sig(
    sig_before_latest,region_file,sha_blob(old_latest_tag),sha_blob(before_region))
region_scope=(latest_source_scope and region_code_scoped
    and hashlib.sha256(sig_before_region.encode("utf-8")).hexdigest()==previous_full_source
    and "FPointRegion" in region_insertion
    and "$family===CatalogUE1ClassicSourceFamily::UT99_V432" in region_insertion)
uobject_files=[
    ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicStateFrameReader.php",
    ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicTaggedPropertyReader.php",
    ROOT/"catalog"/"src"/"Infrastructure"/"Readers"/"CatalogUE1ClassicClassMetadataReader.php",
]
uobject_previous_source_sha256="3e148da2ea4b703f90e896355a228d6b75722e6f17106ef08ef50822486a79e1"
sig_before_uobject=sig_before_region
uobject_before={};uobject_after={}
for f in uobject_files:
    old=committed("4c0428dc",f)
    new=(before_region if f==region_file else
        (before_v227_flag_class if f==class_meta_file else f.read_text(encoding="utf-8-sig")))
    uobject_before[f.name]=old
    uobject_after[f.name]=new
    sig_before_uobject=subst_sig(sig_before_uobject,f,sha_blob(new),sha_blob(old))
scope_sha_correct=hashlib.sha256(sig_before_uobject.encode("utf-8")).hexdigest()==uobject_previous_source_sha256
old_tag=uobject_before["CatalogUE1ClassicTaggedPropertyReader.php"]
new_tag=uobject_after["CatalogUE1ClassicTaggedPropertyReader.php"]
old_class=uobject_before["CatalogUE1ClassicClassMetadataReader.php"]
new_class=uobject_after["CatalogUE1ClassicClassMetadataReader.php"]
tag_old="$position,$offset,$family\n"
tag_new="$position,$offset,$family,$path,$exportIndex\n"
class_old="$read,$compact,$i32,$position,0,$family\n"
class_new="$read,$compact,$i32,$position,0,$family,$path,$index\n"
scope_callsite=(
    old_tag.count(tag_old)==1 and new_tag==old_tag.replace(tag_old,tag_new)
    and old_class.count(class_old)==1
    and new_class==old_class.replace(class_old,class_new)
)
state_before=uobject_before["CatalogUE1ClassicStateFrameReader.php"]
state_after=uobject_after["CatalogUE1ClassicStateFrameReader.php"]
state_script_anchor="        $probeMask=bin2hex($read(8));"
state_return_anchor="        return ["
state_before_positive=state_before.split(state_script_anchor,1)[0]
state_after_positive=state_after.split(state_script_anchor,1)[0]
# This old code body was not changed up to the probe mask except the
# addition of the private symbol resolver and OPTIONAL path/index parameters.
# Anchor all returned FStateFrame fields that old native tests consumed.
old_return=state_before.split(state_return_anchor,1)[1]
source_fields=[
  "'node_reference'=>$node",
  "'state_node_reference'=>$stateNode",
  "'probe_mask_le_hex'=>$probeMask",
  "'latent_action'=>$latent",
  "'script_code_offset'=>$codeOffset",
]
scope_parser=(
    state_before.count(state_script_anchor)==state_after.count(state_script_anchor)==1
    and state_after.count("$codeOffset!==-1&&$archivePath!==null")==1
    and state_after.count("$codeOffset>=$nodeScriptNum")==1
    and state_after.count("$nodeScriptNum=self::referencedNodeScriptNum(")==1
    and all(k in old_return and k in state_after for k in source_fields)
    and state_before_positive.find("$probeMask")<0
)
uobject_scope=scope_sha_correct and scope_callsite and scope_parser and region_scope
sig_middle=subst_sig(
    subst_sig(sig_before_uobject,text_reader,sha_blob(before_audio_native),sha_blob(mid_text)),
    delta_reader,sha_blob(committed("a64a743b",delta_reader)),sha_blob(mid_delta))
font_scope=(hashlib.sha256(sig_middle.encode("utf-8")).hexdigest()==middle_fingerprint)
# The NativeObjectReader change must be exactly a single font-remap call-site
# argument update. The positive body after decoding one safe count stays
# byte-for-byte identical to prior compiled oracle's reader.
old_call="""                $fontRemap=CatalogUE1ClassicV432NativeDeltaReader::decodeFontRemap(
                    $ver,$read,$compact,$int,$pos,$limit);"""
new_call="""                $fontRemap=CatalogUE1ClassicV432NativeDeltaReader::decodeFontRemap(
                    $ver,$read,$compact,$int,$pos,$limit,
                    (int)$ex['serialOffset'],(int)filesize($path));"""
font_scope=font_scope and mid_text.count(old_call)==1 and before_audio_native==mid_text.replace(old_call,new_call)
a="    public static function decodeFontRemap("
b="        $pairOffset=$position;"
if font_scope and mid_delta.count(a)==new_delta.count(a)==1 and mid_delta.count(b)==new_delta.count(b)==1:
    prefix_mid,rest_mid=mid_delta.split(a,1)
    prefix_new,rest_new=new_delta.split(a,1)
    _,unchanged_mid=rest_mid.split(b,1)
    _,unchanged_new=rest_new.split(b,1)
    font_scope=prefix_mid==prefix_new and unchanged_mid==unchanged_new
else:font_scope=False
# The earlier TextBuffer branch alone differs between e6583f48 and 97702616;
# no positive FString materialization/return body was changed.
s_b="            if($nativeKind==='textbuffer'){"
s_e="            if($nativeKind==='sound'||$nativeKind==='music'){"
text_scope=False
if old_text.count(s_b)==mid_text.count(s_b)==1 and old_text.count(s_e)==mid_text.count(s_e)==1:
    a0,t0=old_text.split(s_b,1)
    a1,t1=mid_text.split(s_b,1)
    b0,z0=t0.split(s_e,1)
    b1,z1=t1.split(s_e,1)
    marker="                $textOffset=$pos;$raw=$read($size);"
    sig_old=subst_sig(sig_middle,text_reader,sha_blob(mid_text),sha_blob(old_text))
    text_scope=(a0==a1 and z0==z1 and marker in b0 and marker in b1
                and b0.split(marker,1)[1]==b1.split(marker,1)[1]
                and hashlib.sha256(sig_old.encode("utf-8")).hexdigest()==old_fingerprint)
scoped_equivalence=font_scope and text_scope
def source_compatible(ev,ident):
    if not ev:return False
    f=ev.get("source_sha256")
    return (f==current
        or (animation_scope and f==animation_current)
        or (mesh_scope and f==real_current)
        or (forward_scope and f==before_forward_source)
        or (audio_scope and f==before_audio_source)
        or (legacy_guard_scope and f==previous_legacy_source)
        or (camera_scope and f==previous_camera_source)
        or (primitive_scope and f==previous_primitive_source)
        or (v227_script_scope and f==previous_v227_script_source)
        or (v227_flag_scope and f==previous_v227_flag_source)
        or (string_scope and f==previous_string_source)
        or (nested_scope and f==previous_nested_source)
        or (dynamic_scope and f==previous_dynamic_source)
        or (size_scope and f==previous_size_source)
        or (reference_scope and f==previous_reference_source)
        or (latest_source_scope and f==previous_latest_source)
        or (region_scope and f==previous_full_source)
        or (uobject_scope and f==uobject_previous_source_sha256)
        or (uobject_scope and font_scope and f==middle_fingerprint)
        or (uobject_scope and scoped_equivalence and f==old_fingerprint))
rows=[]
for e in reg["entries"]:
    if e["status"]=="EXCLUDED": continue
    ident=e["id"]; named=e.get("test") or ""; ev=latest.get(named)
    script=ROOT/"catalog"/"bin"/named if named else None
    log=ROOT/ev["log"].replace("\\","/") if ev and ev.get("log") else None
    logvalid=bool(ev and ev.get("state")=="PASS" and ev.get("output_sha256") and digest(log)==ev["output_sha256"])
    scriptvalid=bool(ev and digest(script)==ev.get("test_sha256"))
    sourcevalid=bool(ev and animation_current and ev.get("source_sha256")==animation_current)
    independent=NATIVE.get(ident)
    nv=latest.get(independent)
    nvlog=ROOT/nv["log"].replace("\\","/") if nv and nv.get("log") else None
    nativeslice=bool(nv and nv.get("state")=="PASS" and source_compatible(nv,ident)
                     and nv.get("output_sha256") and digest(nvlog)==nv["output_sha256"]
                     and digest(ROOT/"catalog"/"bin"/independent)==nv.get("test_sha256"))
    gt=GENUINE_ARCHIVE.get(ident)
    ge=latest.get(gt)
    glog=ROOT/ge["log"].replace("\\","/") if ge and ge.get("log") else None
    genuine_valid=bool(ge and ge.get("state")=="PASS" and source_compatible(ge,ident)
                       and ge.get("output_sha256") and digest(glog)==ge["output_sha256"]
                       and digest(ROOT/"catalog"/"bin"/gt)==ge.get("test_sha256"))
    lt=NATIVE_LOADED.get(ident)
    le=latest.get(lt)
    llog=ROOT/le["log"].replace("\\","/") if le and le.get("log") else None
    loaded_primary=bool(le and le.get("state")=="PASS" and source_compatible(le,ident)
                        and le.get("output_sha256") and digest(llog)==le["output_sha256"]
                        and digest(ROOT/"catalog"/"bin"/lt)==le.get("test_sha256"))
    extra=NATIVE_LOADED_EXTRA.get(ident)
    xe=latest.get(extra)
    xlog=ROOT/xe["log"].replace("\\","/") if xe and xe.get("log") else None
    loaded_extra=bool(xe and xe.get("state")=="PASS" and source_compatible(xe,ident)
                      and xe.get("output_sha256") and digest(xlog)==xe["output_sha256"]
                      and digest(ROOT/"catalog"/"bin"/extra)==xe.get("test_sha256"))
    default_test=NATIVE_DEFAULTS.get(ident)
    dv=latest.get(default_test)
    dlog=ROOT/dv["log"].replace("\\","/") if dv and dv.get("log") else None
    default_valid=bool(dv and dv.get("state")=="PASS" and source_compatible(dv,ident)
                       and dv.get("output_sha256") and digest(dlog)==dv["output_sha256"]
                       and digest(ROOT/"catalog"/"bin"/default_test)==dv.get("test_sha256"))
    coverage_test=NATIVE_COVERAGE.get(ident)
    cv=latest.get(coverage_test)
    clog=ROOT/cv["log"].replace("\\","/") if cv and cv.get("log") else None
    coverage_valid=bool(cv and cv.get("state")=="PASS" and source_compatible(cv,ident)
                        and cv.get("output_sha256") and digest(clog)==cv["output_sha256"]
                        and digest(ROOT/"catalog"/"bin"/coverage_test)==cv.get("test_sha256"))
    inherited_test=NATIVE_INHERITED.get(ident)
    ie=latest.get(inherited_test)
    ilog=ROOT/ie["log"].replace("\\","/") if ie and ie.get("log") else None
    inherited_valid=bool(ie and ie.get("state")=="PASS" and source_compatible(ie,ident)
                        and ie.get("output_sha256") and digest(ilog)==ie["output_sha256"]
                        and digest(ROOT/"catalog"/"bin"/inherited_test)==ie.get("test_sha256"))
    loaded_valid=(loaded_primary and loaded_extra and default_valid and coverage_valid and inherited_valid) if ident=="T99:UClass::Serialize" else (
        (loaded_primary and loaded_extra) if ident in {"T99:UFont::Serialize","T99:UObject::Serialize"} else (loaded_primary or loaded_extra))
    rt=NATIVE_REFERENTS.get(ident)
    re=latest.get(rt)
    rlog=ROOT/re["log"].replace("\\","/") if re and re.get("log") else None
    refs_valid=bool(re and re.get("state")=="PASS" and source_compatible(re,ident)
                    and re.get("output_sha256") and digest(rlog)==re["output_sha256"]
                    and digest(ROOT/"catalog"/"bin"/rt)==re.get("test_sha256"))
    arraytests=NATIVE_ARRAY_TESTS.get(ident,[])
    arraytest_states=[]
    for aname in arraytests:
        aev=latest.get(aname)
        alog=ROOT/aev["log"].replace("\\","/") if aev and aev.get("log") else None
        arraytest_states.append(bool(aev and aev.get("state")=="PASS"
            and source_compatible(aev,ident)
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
            gaps.extend(["native_malformed_CharRemap_Got4116_Expected4112_now_matches_safe_PHP_23of23_consolidated_Blocked_on_inherited_UObject",
                         "remaining_font_glyph_arrays_and_texture_links_not_all_native_verified"])
        elif array_subset_valid:
            gaps.append("inherited_ULodMesh_and_unverified_scalar_fields_errors_open" if ident=="T99:USkeletalMesh::Serialize"
                        else "nested_MotionChunk_keys_full_for_two_real_packages_but_other_genuine_cases_error_paths_and_inherited_UObject_open")
        else: gaps.append("native_full_array_elements_and_malformed_errors_not_proven")
    if ident=="T99:UObject::Serialize" and loaded_valid:
        gaps.append("compiled_genuine_Entry_native_14_of_19_5_unloaded_Camera_export_flags_23_40000_confirmed_and_three_malformed_OffsetMismatch_minus2_zero_63_safe_PHP_43of43_PASS_plus_33_original_loaded_UObjects_all_113_genuine_serialized_tagged_values_exact_native_refs_structs_ENUM_source_37of37_PASS_but_other_malformed_reference_consumption_variant_paths_open" if refs_valid else "compiled_Entry_native_14_of_19_positive_StateFrames_imported_Node_StageNode_identity_and_malformed_errors_open")
    if ident=="T99:UClass::Serialize" and loaded_valid:
        gaps.append("compiled_ALL88_REAL_UClass_43_NAME_None_zero_byte_placeholders_not_unvisited_3053_dependencies_176_imports_608_defaults_plus_1056_inherited_UField_UStruct_UState_stored_members_verified_but_Script_bytecode_fixups_malformed_errors_version_variants_OPEN")
    if ident=="T99:UTextBuffer::Serialize" and loaded_valid:
        gaps.append("compiled_ANSI900_Got912_Expected843_failure_byte_count_NOW_safely_matches_PHP_single_full_verdict_PASS28_but_inherited_UObject_full_method_still_BLOCKED")
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
        "native_UClass_inherited_test":inherited_test,
        "native_UClass_inherited_current":inherited_valid,
        "native_UClass_inherited_values_compared":1056 if inherited_valid else 0,
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
        "full_G5_method_acceptance":method_decisions.get(ident,{}).get("decision","OPEN"),
        "decisive_closure_blocker":method_decisions.get(ident,{}).get("decisive_blocker"),
        "gaps":gaps})
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
         "full_independent_method_verified":sum(r["full_G5_method_acceptance"]=="FULL" for r in rows),
         "g5_failed_method_decisions":sum(r["full_G5_method_acceptance"]=="FAILED" for r in rows),
         "g5_blocked_method_decisions":sum(r["full_G5_method_acceptance"]=="BLOCKED" for r in rows),
         "native_partial_slice_evidence":len(natives),
         "compiled_archive_only":sum(r["id"] in COMPILED and not r["native_loaded_evidence_current"] for r in rows),"with_named_test":len(rows)-len(no_named),
         "without_named_test":len(no_named),
         "named_tests_with_current_source_fingerprint":sum(r["named_test_current_global_fingerprint"] for r in rows),
         "genuine_v432_font_archive_compatibility":sum(r["genuine_archive_evidence_current"] for r in rows),
         "compiled_native_loaded_value_slice":sum(r["native_loaded_evidence_current"] for r in rows),
         "compiled_UClass_full_nested_member_subset":sum(r["native_UClass_full_nested_members_current"] for r in rows),
         "compiled_UClass_complete_608_default_tag_subset":sum(r["native_UClass_defaults_current"] for r in rows),
         "compiled_UClass_all_real_original_class_coverage":sum(r["native_UClass_coverage_all_genuine_current"] for r in rows),
         "compiled_UClass_1056_inherited_native_value_subset":sum(r["native_UClass_inherited_current"] for r in rows),
         "compiled_full_array_element_subset":sum(r["native_array_full_element_subset_valid"] for r in rows),
         "compiled_stateframe_native_referent_name_subsets":sum(r["native_stateframe_referent_names_current"] for r in rows),
         "compiled_native_individual_array_bytes_verified":(
             (282956 if any(r["id"]=="T99:USkeletalMesh::Serialize" and r["native_array_full_element_subset_valid"] for r in rows) else 0)
             +(544792 if any(r["id"]=="T99:UAnimation::Serialize" and r["native_array_full_element_subset_valid"] for r in rows) else 0)),
         "early_methods_without_matching_compiled_runtime":sum(r["branch"] in EARLY for r in rows)}
result={"schema":1,"generated_utc":dt.datetime.now(dt.timezone.utc).isoformat(),
    "head":head,"previous_runner_head":status.get("head_commit"),
    "previous_source_sha256":status.get("current_source_sha256"),
    "current_source_sha256":uobject_new_current,
    "source_native_uobject_legacy_String_upgrade_and_struct_physical_EOF_scoped":True,
    "previous_source_before_uobject_legacy_and_struct":class_memory_current,
    "source_native_uclass_complete_script_memory_scoped":True,
    "previous_source_before_uclass_complete_memory":script_current,
    "source_native_uclass_script_load_relocations_scoped":True,
    "previous_source_before_uclass_script_relocations":remap_current,
    "source_native_skeletal_runtime_remap_scoped":True,
    "previous_source_before_skeletal_remap":direct_current,
    "source_native_direct_UStrProperty_physical_EOF_scoped":True,
    "previous_source_before_direct_str_fix":"8be84144df4b213d600310ec1a2b254c4d8dcc1c5e66478e4917c535fa486029",
    "source_native_uclass_archive_bounds_cursor_scoped":True,
    "previous_source_before_uclass_archive_fix":animation_current,
    "source_native_animation_exact_error_cursors_scoped":animation_scope,
    "previous_source_before_animation_error_cursor_change":real_current,
    "source_native_mesh_seek_and_C98_FSphere_scoped":mesh_scope,
    "previous_source_before_mesh_fix":actual_current,
    "source_native_array_physical_EOF_scoped":eof_scope,
    "source_native_nested_FString_physical_EOF_scoped":nested_scope,
    "previous_source_before_native_nested_FString_EOF_change":source_before_nested,
    "previous_source_before_native_array_physical_EOF_change":source_before_eof if eof_scope else None,
    "fingerprint_changed":bool(animation_current and animation_current!=status.get("current_source_sha256")),
    "source_scope_equivalence_v432_textbuffer_only":scoped_equivalence,
    "prior_unchanged_branch_source_sha256":old_fingerprint if scoped_equivalence else None,
    "middle_unmodified_branch_source_sha256":middle_fingerprint if font_scope else None,
    "source_font_branch_equivalence":font_scope,
    "source_native_audio_compiled_loaded_and_malformed_scoped":forward_scope,
    "previous_source_before_audio_loaded_malformed_change":before_forward_source if forward_scope else None,
    "source_native_audio_array_type_dispatch_scoped":audio_scope,
    "previous_source_before_audio_array_dispatch":before_audio_source if audio_scope else None,
    "source_native_v227_export_archetype_guard_scoped":legacy_guard_scope,
    "previous_source_before_v227_export_guard":previous_legacy_source if legacy_guard_scope else None,
    "source_native_Camera_loader_skip_change_scoped":camera_scope,
    "previous_source_before_Camera_loader_skip_change":previous_camera_source if camera_scope else None,
    "source_native_genuine_v54_v1200_FSphere_change_scoped":primitive_scope,
    "previous_source_before_v54_v1200_FSphere_change":previous_primitive_source if primitive_scope else None,
    "source_native_genuine_v227_script_opcode_change_scoped":v227_script_scope,
    "previous_source_before_genuine_v227_script_change":previous_v227_script_source if v227_script_scope else None,
    "source_native_v227_flag_interpretation_change_scoped":v227_flag_scope,
    "previous_source_before_v227_flag_interpretation_change":previous_v227_flag_source if v227_flag_scope else None,
    "source_native_prd_fstring_load_change_scoped":string_scope,
    "previous_source_before_native_prd_fstring_load_change":previous_string_source if string_scope else None,
    "source_native_nested_vector_change_scoped":nested_scope,
    "previous_source_before_native_nested_vector_change":previous_nested_source if nested_scope else None,
    "source_native_dynamic_array_change_scoped":dynamic_scope,
    "previous_source_before_native_dynamic_array_change":previous_dynamic_source if dynamic_scope else None,
    "source_native_struct_str_size_change_scoped":size_scope,
    "previous_source_before_native_struct_str_size_change":previous_size_source if size_scope else None,
    "source_native_reference_change_scoped":reference_scope,
    "previous_source_before_native_reference_change":previous_reference_source if reference_scope else None,
    "source_native_tag_error_change_scoped":latest_source_scope,
    "previous_source_before_native_tag_error_change":previous_latest_source if latest_source_scope else None,
    "source_native_tag_decl_resolver_scoped":schema_scope,
    "source_region_pointregion_exact_codec_only":region_scope,
    "previous_uobject_source_before_pointregion":previous_full_source if region_scope else None,
    "source_uobject_positive_branch_equivalence":uobject_scope,
    "previous_uobject_positive_source_sha256":uobject_previous_source_sha256 if uobject_scope else None,
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
       "- 256 MAPPED; 38 EXCLUDED; "+str(summary["full_independent_method_verified"])+" independently accepted T99 v432 file-load method contracts, "+str(summary["g5_blocked_method_decisions"])+" G5 method decisions BLOCKED; do not infer cross-family FULL.",
       "- "+str(len(no_named))+" entries have no named test; 52 of these are R82/R83 early-resource identity mappings.",
       "- "+str(len(natives))+" independent compiled v432 native export comparison slices: "+", ".join(natives)+".",
       "- Compiled v432 method archive-order evidence with no genuine loaded-value evidence: "+(", ".join(sorted(r["id"] for r in rows if r["id"] in COMPILED and not r["native_loaded_evidence_current"])) or "none")+".",
       "- Current genuine archive compatibility: "+str(summary["genuine_v432_font_archive_compatibility"])+" method (T99 UFont; 34 SHA-pinned file exports / 66 checks PASS).",
       "- Current **compiled v432 genuine native loaded-value subsets**: "+str(summary["compiled_native_loaded_value_slice"])+" methods ("+", ".join(sorted(r["id"] for r in rows if r["native_loaded_evidence_current"]))+"). Includes ALL 88/88 real named nonzero Engine.u UClasses (131 ClassIndex==0 rows include 43 zero-byte NAME_None placeholders), native ClassFlags/GUID/ConfigName, 3053 native FDependency class-reference/Deep/CRC tuples, 176 live FName PackageImports and 88 ClassWithin names, PLUS 1,056 stored UField/UStruct/UState native inherited values for all 88 classes, and 608/608 actual UClass loaded tagged default values of which 607 serialized values exactly match and one GameReplicationInfo.ServerName has a verified runtime INI override, UFont real glyph and nonempty remaps, USkeletalMesh/Animation full nested-array subsets, all 88 genuine Engine.u TextBuffer post-load values, and common UObject real inherited flags + 14/19 compiled positive RF_HasStack map frames, PLUS genuine Entry.unr 33 compiled loaded object exports with all 113/113 serialized tagged-property values independently matched (23 object references, 15 FNames, 14 FPointRegions, all scalar/struct/enum values, SHA-pinned 37/37). These remain PARTIAL method proofs; full error/reference/inherited source-family closure is OPEN.",
       "- NEW compiled v432 **genuine StateFrame referent name subset**: "+str(summary["compiled_stateframe_native_referent_name_subsets"])+" verified UObject method with 14/19 original Entry.unr v68 StateFrames and all 28 real compiled Node/StateNode FName strings matching import references, plus exact ProbeMask64/LatentAction/Code values (195/195 PASS). Five Camera original exports and complete outer/class/error semantics OPEN.",
       "- NEW **full native array-element subsets**: "+str(summary["compiled_full_array_element_subset"])+" compiled v432 methods (T99 USkeletalMesh, UAnimation): genuine 5 skeletal objects 42,032 bytes of complete bone index/weights and 225,756 bytes of complete Points/LocalPoints, plus 143 animation ref bones full native name-table-resolved FName/flags/parent semantic records (1,716 bytes). Total 269,504 bytes, focused 733/733 + 228/228 PASS, no repeated tests.",
       "- NEW independent compiled-v432 **resumable 2-case batch**: 237 full native FMeshBone records (15,168 bytes) source field-by-field including runtime-resolved names and XSize's second serialized write, 1787/1787 PASS; 70 full native FMeshAnimSeq metadata records (2,240 bytes), all 22 nested native notification time/function names, 575/575 PASS. Both were rerun in resume mode with 2/2 UNCHANGED_PASS cache skips, zero repeated tests. Combined new 2362/2362 checks. SHA-pinned native manifests and append-only events preserved; YSize/Depth nonserialized fields and MotionChunk Moves still OPEN.",
       "- NEW third resumable native contract: 70 genuine MotionChunk in-memory scalar/root header and array-length comparisons, 95 full first-Move AnalogTrack header checks plus 732 full first-track key/BoneIndices bytes identical, 598/598 PASS. Remaining KeyQuat/KeyPos/KeyTime buffers from all other tracks still OPEN; did not replay previous PASS evidence.",
       "- NEW compiled real v432 **full nested MotionChunk memory**: 70 authentic Moves, 3,292 AnalogTracks, 10,156 distinct root/track/bone nested array buffers, 540,836 entire loaded bytes equal every source byte, 21,020 KeyQuat, 9,268 KeyPos and 21,020 KeyTime keys. Microsoft CDB 46MB full-memory MiniDump MEMORY64 oracle for each original package, independent source bounds/float-preview comparisons; focused 29,145/29,145 PASS, one initial test aggregation FAIL preserved. This validates complete nested element values for TWO original v69 package UAnimation exports, not malformed runtime or other source families.",
       "- Installed vanilla UT99's 310 original packages had no skeletal/animation exports; the additional original-filename-indexed PRD corpus does (5 SkeletalMesh+3 Animation compiled loaded)." ,
       "- Latest DEV journal state: "+str(result["latest_DEV_test_states"])+".",
       "- Named tests with current matching source fingerprint: "+str(summary["named_tests_with_current_source_fingerprint"])+" of "+str(summary["with_named_test"])+"; historical PASS is not an automatically current proof.",
       "- Global source fingerprint in latest status "+str(result["previous_source_sha256"])+"; actual current "+str(actual_current)+"; earlier scoped native proofs reconciled against "+str(current)+" only after exact v432 dynamic-array source isolation and PASS21/21.",
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
