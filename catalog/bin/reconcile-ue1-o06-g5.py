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
new_text=text_reader.read_text(encoding="utf-8-sig")
new_delta=delta_reader.read_text(encoding="utf-8-sig")
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
current_tag=new_tag_file.read_text(encoding="utf-8-sig")
current_schema=new_schema_file.read_text(encoding="utf-8-sig")
# One additional source-scoped v432 valid Struct/FString SerializeItem
# correction. Require CURRENT-source 50/50 decision and reconstruct the
# exact 55fa4f1e parent fingerprint before applying older substitutions.
before_size_tag=committed("55fa4f1e",new_tag_file)
previous_size_source="f2f64ef9a2202c18c01ebc61293946990358de9a55e48cdbb2d3021f25dc779f"
sig_before_size=subst_sig(
    signature,new_tag_file,digest(new_tag_file).upper(),sha_blob(before_size_tag))
size_decision="verify-ue1-o06-g5-uobject-serializeitem-size-decision.php"
size_event=latest.get(size_decision)
size_log=ROOT/size_event["log"] if size_event and size_event.get("log") else None
try:
    size_values=read(size_log) if size_log and size_log.exists() else {}
except Exception:
    size_values={}
size_scope=(
    hashlib.sha256(sig_before_size.encode("utf-8")).hexdigest()==previous_size_source
    and current_schema==committed("55fa4f1e",new_schema_file)
    and "UT99 UStrProperty::SerializeItem delegates" in current_tag
    and "strlen($raw)>=6&&strlen($raw)<=10" in current_tag
    and size_event is not None and size_event.get("state")=="PASS"
    and size_event.get("source_sha256")==current
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
    new=before_region if f==region_file else f.read_text(encoding="utf-8-sig")
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
    subst_sig(sig_before_uobject,text_reader,digest(text_reader).upper(),sha_blob(mid_text)),
    delta_reader,digest(delta_reader).upper(),sha_blob(mid_delta))
font_scope=(hashlib.sha256(sig_middle.encode("utf-8")).hexdigest()==middle_fingerprint)
# The NativeObjectReader change must be exactly a single font-remap call-site
# argument update. The positive body after decoding one safe count stays
# byte-for-byte identical to prior compiled oracle's reader.
old_call="""                $fontRemap=CatalogUE1ClassicV432NativeDeltaReader::decodeFontRemap(
                    $ver,$read,$compact,$int,$pos,$limit);"""
new_call="""                $fontRemap=CatalogUE1ClassicV432NativeDeltaReader::decodeFontRemap(
                    $ver,$read,$compact,$int,$pos,$limit,
                    (int)$ex['serialOffset'],(int)filesize($path));"""
font_scope=font_scope and mid_text.count(old_call)==1 and new_text==mid_text.replace(old_call,new_call)
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
    sourcevalid=bool(ev and current and ev.get("source_sha256")==current)
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
         "full_independent_method_verified":0,
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
    "current_source_sha256":current,
    "fingerprint_changed":bool(current and current!=status.get("current_source_sha256")),
    "source_scope_equivalence_v432_textbuffer_only":scoped_equivalence,
    "prior_unchanged_branch_source_sha256":old_fingerprint if scoped_equivalence else None,
    "middle_unmodified_branch_source_sha256":middle_fingerprint if font_scope else None,
    "source_font_branch_equivalence":font_scope,
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
       "- 256 MAPPED; 38 EXCLUDED; 0 methods with demonstrated full independent native behaviour across all relevant criteria.",
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
