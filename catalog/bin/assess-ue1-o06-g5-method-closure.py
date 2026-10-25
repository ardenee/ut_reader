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
import subprocess
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
  "reason":"Original PRD verified UE1 corpus investigated on DEV: 239 original .u/.unr SHA-staged, 150150 real exports with six actual dynamic ArrayProperty schemas, 433 source-decoded Camera frames across archive versions61/63/68/69, plus 43 genuine FireTexture native Sparks tails totalling 4573 source-read FSpark records. NEW actual compiled v432 Core.dll successfully LoadPackage an original PRD v61 map: 312 exports, 308 exact native class/object identity instances; all 308 corresponding source-driven PHP tagged archives parse successfully, including 184/184 native class-specific serialization tails; exactly Camera0/1/2/3 are uninstantiated in the native engine. Compiled v432 exported 8515 property text lines; all 926 actual saved tags now have positive native loaded-value comparisons: 802 typed ExportProperties fields plus 124 ACTUAL direct compiled AActor::XLevel memory pointers previously text-omitted by the transient Port gate; full class-qualified export-index2 objects match in all 124 actors, zero mismatches. Non-saved default-only runtime text lines are not claimed as saved-value comparisons. Latest current-source v61 original native verdict PASS24/24. Previous native compiled v432 dynamic Int/Name/Object and Core.Vector arrays, malformed exact archive cursors, 113 Entry genuine values and 43 StateFrame checks all source-SHA reused without rerun. Isolated nonempty FString generated archive source decodes correctly but compiled native LoadPackage fails with invalid 0xBAADF00D; source FArray::Add noninit hazard, production marks actual runtime-loaded string parity NOT_VERIFIED. Shared v60-61 v1200/v432 frame wire is source-equivalent and is not retested; C98 v1200 and C227 v227 must be evaluated independently, NOT used as an inappropriate prerequisite for the T99 v432 UObject method. Camera loader exclusions are CLOSED. Within T99 v432, source-legal nested dynamic FString stock-native loaded values remain NOT_VERIFIED: canonical malloc yields 0xBAADF00D, but original compiled Core.dll under isolated zero-initializing allocation successfully loads and agrees with two source-archived strings and two FVector structs (allocator-conditioned proof, NOT a stock native full method acceptance); independent complete UObject source-legal tag/default/property/postload and malformed stream variants remain OPEN. One new compiled count31 dynamic ArrayProperty physical EOF case is fixed, SHA-verified, and does not close the other branches.",
  "next":"T99 v432 UObject scope only: close genuinely unverified source-legal nested loaded values (especially dynamic array<FString> native crash/loader outcome), context-specific stream errors and full class/preload/tagged properties behavior before independent seven-gate signoff. C98 v1200 and C227 v227 remain separate O06 source profile contracts, not T99 gate dependencies. DO NOT retest SHA-pinned original v61 308/926/124 properties, prior 113 Entry/43 StateFrames, 6 native dynamic cases, 3 vectors or the new native count31 malformed array EOF; do not redo four already FULL v432 subclass method oracles. PRD untouched.",
  "blocker":"T99_v432_unverified_nonempty_FString_array_native_materialization_other_nested_tagged_property_load_error_variants_and_independent_full_method_review",
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
  "reason":"New original v432 Core.dll+Engine.dll native whole-method comparison used actual LoadPackage/StaticLoadObject on three genuine v69 UAnimation exports with 0/2/68 Moves, 0/2/68 AnimSeqs and all 143 FNamedBone source referents. Current production source reader agrees on 3,694 individually reviewed native fields/records (3,667 emitted object/record lines), all 70 MotionChunk arrays, and 540,836 original numeric key/index bytes; archive fully consumed in all three, with zero source errors. All three inherit exactly RF_HasStack=0, saved tag count 0. Eight distinct disposable original-corruption cases (RefBone FName invalid, AnimSeq FName invalid, negative parent and nested array counts, truncated original) are rejected by original native compiled v432 and source PHP, with every source, DLL, executable, original, mutation, log SHA pinned in UE1-O06-G5-T99-ANIMATION-ONE-METHOD-NATIVE.json. Subsequent original compiled v432 CDB first-chance capture verifies six exact ULinkerLoad archive error cursors against the current source-specific reader (3 FName failures at 153323/34393/593470, 3 source TArray negative-count physical EOF failures at 899657/899657/413388), each fully source/binary/input/log SHA sealed with 91/91 current DEV acceptance; prior eight native rejection outcomes and all 3694 positive loaded fields remain intact. Method-specific malformed-input gate is complete for these applicable FName/TArray and physical EOF failure paths. The independent method remains BLOCKED on source-legal inherited UObject/tagged-property/version variants and final independent method review; do not claim FULL.",
  "next":"Exact six FName/TArray compiled native cursor differences now CLOSED; reuse pinned CDB logs and the 91/91 focus proof. Next independently resolve applicable inherited UObject tagged/default/StateFrame and archive-version variants for UAnimation and issue final seven-criterion method acceptance, without repeating the 3,694 loaded-value comparisons.",
  "blocker":"inherited_UObject_tagged_property_StateFrame_version_variants_and_independent_final_method_signoff",
 },
 "T99:USound::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "complete_members":True,
  "reason":"Original compiled UT99 v432 Core+Engine LoadPackage loaded 9 genuine Sound objects at versions61/66. All 424068 Data bytes, FileType, OriginalSize and all 9 duration IEEE754 bit patterns match Epic source and PHP archive. Compiled malformed native Sound invalid FileType fails; zero-size WAV Sound native faults at FWaveModInfo::ReadWaveInfo WavData(0), now fail-closed safely. Sound method-specific native loaded and tested negative criteria VERIFIED; inherited UObject full method and final seven-gate independent signoff still OPEN.",
  "next":"Native Sound loaded-value and scoped malformed-input work CLOSED: do not retest 9 original Sounds or two negative oracles. Only shared UObject inherited seven-gate acceptance and independent whole-method signoff remain; avoid redundant Sound-specific probes.",
  "blocker":"inherited_UObject_full_method_and_independent_seven_gate_review",
 },
 "T99:UMusic::Serialize":{
  "result":"BLOCKED",
  "pinned":True,
  "current":True,
  "complete_members":True,
  "reason":"Original compiled UT99 v432 Core+Engine LoadPackage loaded 2 genuine Music objects at versions61/64. All 792404 Data bytes, FileType and OriginalSize match Epic original source and PHP. Three distinct native Music mutations: zero lazy-count with forward skip successfully loads, invalid FileType and backward SeekPos fail. Production reader corrected forward skip, original positives unchanged. Music-specific loaded and tested negative criteria VERIFIED; inherited UObject full method and final seven-gate independent signoff still OPEN.",
  "next":"Native Music loaded-value and scoped malformed-input work CLOSED: do not retest 2 original Musics or three negative oracles. Only shared UObject inherited seven-gate acceptance and independent whole-method signoff remain; avoid redundant Music-specific probes.",
  "blocker":"inherited_UObject_full_method_and_independent_seven_gate_review",
 },
}
assert set(status)==set(reg["methods"])
# Prior four SHA-pinned FULL T99 method source profiles are unchanged by
# ONE independently original-compiled, SHA-verified malformed dynamic
# UArrayProperty count/physical-EOF branch. The 21/21 focused acceptance
# and exact old/new tagged-reader isolation are independently verified in
# reconcile-ue1-o06-g5.py; no previously closed native loader replay.
def scoped_source_current(value):
    if value==reconciliation["current_source_sha256"]:
        return True
    # Two strictly v432 UAnimation-only source edits, paired with exact
    # compiled first-chance cursors and original genuine values, cannot
    # revoke the previously accepted unrelated method proofs.
    if (reconciliation.get("source_native_animation_exact_error_cursors_scoped")
        and value==reconciliation.get("previous_source_before_animation_error_cursor_change")):
        return True
    # UMesh/ULodMesh-only correction cannot invalidate previously sealed
    # Sound/Music/Font/TextBuffer native semantics or tagged UObject proof.
    if (reconciliation.get("source_native_mesh_seek_and_C98_FSphere_scoped")
        and value==reconciliation.get("previous_source_before_mesh_fix")):
        return True
    # Retain original SHA-pinned evidence across the two independent,
    # source-isolated malformed-array fixes. Never claim arbitrary older
    # fingerprints are current without corresponding reconciliation proof.
    if (reconciliation.get("source_native_nested_FString_physical_EOF_scoped")
        and value==reconciliation.get("previous_source_before_native_nested_FString_EOF_change")):
        return True
    return bool(reconciliation.get("source_native_array_physical_EOF_scoped")
        and value==reconciliation.get("previous_source_before_native_array_physical_EOF_change"))

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
    if name in ("T99:USound::Serialize","T99:UMusic::Serialize"):
        events=[json.loads(line) for line in (C/"UE1-O06-TEST-EVENTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if line.strip()]
        native_test="verify-ue1-o06-g5-audio-loaded-and-malformed-acceptance.php"
        native_ev=next((item for item in reversed(events)
            if item.get("test")==native_test and item.get("state")=="PASS"),None)
        assert native_ev is not None
        native_log=ROOT/native_ev["log"].replace("\\","/")
        assert sha(native_log)==native_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/native_test)==native_ev["test_sha256"]
        assert scoped_source_current(native_ev["source_sha256"])
        assert reconciliation["source_native_audio_compiled_loaded_and_malformed_scoped"]
        result=read(native_log)
        assert result["ok"] and result["checks"]==66 and result["passed"]==66
        assert result["compiled_loaded_sound_objects"]==9
        assert result["compiled_loaded_music_objects"]==2
        assert result["native_memory_bytes_match"]==1216472
        assert result["Sound_Duration_exact_bits"]==9
        assert result["compiled_distinct_malformed_cases"]==5
        assert result["Music_zero_lazy_skipped_unloaded_bytes"]==734772
        assert result["native_loaded_and_malformed_acceptance"]=="VERIFIED_SCOPE"
        assert result["full_Sound_Music_method"]=="BLOCKED_SHARED_UOBJECT_AND_FINAL_GATES"
        assert reconciliation["source_native_audio_array_type_dispatch_scoped"]
        gates["archive_bytes_and_consumption"]="VERIFIED"
        gates["malformed_input_error_semantics"]="VERIFIED"
        gates["current_acceptance_tests"]="VERIFIED"
        old["method_specific_native_loaded_values"]="VERIFIED"
        old["method_specific_compiled_native_malformed_cases"]="VERIFIED_SCOPED"
        old["remaining_shared_full_method_blocker"]="T99:UObject::Serialize"
        old["genuine_loaded_objects"]=(9 if name=="T99:USound::Serialize" else 2)
        old["genuine_loaded_bytes"]=(424068 if name=="T99:USound::Serialize" else 792404)
        old["negative_native_cases"]=(2 if name=="T99:USound::Serialize" else 3)
        old["original_native_audio_source_SHA_pinned"]=True
        old["native_compiled_prerequisite_fully_closed"]=False
    if name in ("T99:USound::Serialize","T99:UMusic::Serialize"):
        # Independent complete source-profile method closure. T99 v432
        # USound/UMusic inherit the specific no-stack/no-tag UObject
        # branch observed by original compiled loader, NOT every possible
        # UObject subclass or C98/C227 native version behavior.
        final_test="verify-ue1-o06-g5-t99-audio-independent-full-method-review.php"
        finale=next((r for r in reversed(events)
            if r.get("test")==final_test and r.get("state")=="PASS"),None)
        assert finale is not None, "Missing method-scoped independent seven-gate decision"
        final_log=ROOT/finale["log"].replace("\\","/")
        assert sha(final_log)==finale["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/final_test)==finale["test_sha256"]
        assert scoped_source_current(finale["source_sha256"])
        verdict=read(final_log)
        assert verdict["ok"] and verdict["checks"]==63 and verdict["passed"]==63
        assert verdict["source_profile"]=="UT99-v432"
        assert verdict["native_loaded_Sound"]==9 and verdict["native_loaded_Music"]==2
        assert verdict["source_inherited_validated_objects"]==11
        assert verdict["native_byte_values_matched"]==1216472
        assert verdict["native_negative_distinct_cases"]==5
        assert verdict["native_engine_replays_this_test"]==0
        assert verdict["general_T99_UObject_FULL"]=="BLOCKED_INDEPENDENTLY"
        assert set(verdict["seven_gates"])==set(criteria)
        assert all(v=="VERIFIED" for v in verdict["seven_gates"].values())
        assert verdict["method_decisions"][name]=="FULL_T99_V432_SOURCE_PROFILE_ONLY"
        source_review=C/"UE1-O06-G5-T99-AUDIO-INHERITED-SUPER-REVIEW.json"
        assert source_review.is_file()
        class_label="USOUND" if name=="T99:USound::Serialize" else "UMUSIC"
        artifact=C/("UE1-O06-G5-"+class_label+"-SOURCE-PROFILE-FULL-ACCEPTANCE.json")
        assert artifact.is_file()
        closure=read(artifact)
        assert closure["decision"]=="FULL" and closure["method"]==name
        assert closure["scope"]=="UT99_v432_T99_source_profile_package_reader_loading_only"
        assert scoped_source_current(closure["current_production_source_sha256"])
        assert closure["independent_final_acceptance_script_sha256"]==finale["test_sha256"]
        assert closure["independent_final_acceptance_log_sha256"]==finale["output_sha256"]
        assert closure["review_source_manifest_sha256"]==sha(source_review)
        assert closure["seven_verified_criteria"]==verdict["seven_gates"]
        assert closure["compiled_engine_reruns_in_final_review"]==0
        assert closure["general_T99_UObject_method_decision"]=="BLOCKED_SEPARATELY"
        assert reconciliation["source_native_audio_compiled_loaded_and_malformed_scoped"]
        assert reconciliation["source_native_audio_array_type_dispatch_scoped"]
        assert entry["current"] and entry["complete_members"]
        gates.update(verdict["seven_gates"])
        entry["result"]="FULL"
        entry["reason"]=("Independently FULL UT99 v432 T99 source-profile file-read acceptance: "
            "actual original compiled native loaded data and inherited no-stack/no-tag "
            "UObject::Serialize branch, versions <=61 and >61, 5 observed native errors, "
            "7/7 SHA-current gates. General UObject/C98/C227 remains separate.")
        entry["blocker"]=None
        entry["next"]="T99 v432 Sound/Music method complete. Do not retest. Only general UObject and other source families remain."
        old["closure_artifact"]=str(artifact.relative_to(ROOT)).replace("\\","/")
        old["closure_sha256"]=sha(artifact)
        old["remaining_shared_full_method_blocker"]=None
        old["method_scoped_inherited_super_closed"]=True
        old["native_compiled_prerequisite_fully_closed"]=True
    if name=="T99:UTextBuffer::Serialize":
        # Independent complete T99 v432 source-profile method read
        # contract: 88 original native TextBuffer objects, all 88
        # no-stack/no-tag actual inherited archive envelopes (compact
        # NAME_None 0x5034 two physical bytes), 88 loaded Pos/Top/Text
        # values and three compiled positive + one native negative.
        # Does NOT infer generic UObject or C98/C227 closure.
        final_test="verify-ue1-o06-g5-t99-textbuffer-independent-full.php"
        finale=next((r for r in reversed(events)
            if r.get("test")==final_test and r.get("state")=="PASS"),None)
        assert finale is not None, "Missing TextBuffer independent method acceptance"
        final_log=ROOT/finale["log"].replace("\\","/")
        assert sha(final_log)==finale["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/final_test)==finale["test_sha256"]
        assert scoped_source_current(finale["source_sha256"])
        v=read(final_log)
        assert v["ok"] and v["checks"]==302 and v["passed"]==302
        assert v["scope"]=="UT99 v432 file-loading" and v["review"]=="FULL_SOURCE_PROFILE_ONLY"
        assert v["observed_genuine_native_loaded_objects"]==88
        assert v["original_native_memory_UTF16_bytes"]==1007796
        assert v["inherited_real_source_compact_name_None_2bytes"]==88
        assert v["compiled_native_positive_variants"]==3
        assert v["compiled_native_negative_variants"]==1
        assert v["native_overlong_cursor"]=="Got 912, Expected 843"
        assert v["compiled_native_reruns"]==0
        assert v["generic_T99_UObject"]=="BLOCKED_INDEPENDENTLY"
        assert set(v["seven_gates"])==set(criteria)
        assert all(x=="VERIFIED" for x in v["seven_gates"].values())
        review=C/"UE1-O06-G5-T99-TEXTBUFFER-INHERITED-88-SUPER-REVIEW.json"
        artifact=C/"UE1-O06-G5-UTEXTBUFFER-SOURCE-PROFILE-FULL-ACCEPTANCE.json"
        assert artifact.is_file() and review.is_file()
        closure=read(artifact)
        assert closure["decision"]=="FULL"
        assert closure["method"]==name
        assert closure["scope"]=="UT99_v432_T99_source_profile_package_reader_loading_only"
        assert scoped_source_current(closure["current_production_source_sha256"])
        assert closure["independent_final_acceptance_script_sha256"]==finale["test_sha256"]
        assert closure["independent_final_acceptance_log_sha256"]==finale["output_sha256"]
        assert closure["review_source_manifest_sha256"]==sha(review)
        assert closure["seven_verified_criteria"]==v["seven_gates"]
        assert closure["native_engine_reruns_in_final_review"]==0
        assert closure["general_T99_UObject_method_decision"]=="BLOCKED_SEPARATELY"
        assert entry["current"] and entry["complete_members"]
        gates.update(v["seven_gates"])
        entry["result"]="FULL"
        entry["blocker"]=None
        entry["reason"]=("Independently FULL UT99 v432 T99 TextBuffer file-read "
            "method: original 88 Pos/Top/Text native values, 88 original "
            "two-byte compact NAME_None inherited envelopes, 3 native "
            "positive mutations and matching native oversized failed "
            "cursor. General UObject and C98/C227 remain BLOCKED.")
        entry["next"]="T99 v432 TextBuffer fully accepted. No further original 88 native replay."
        old["closure_artifact"]=str(artifact.relative_to(ROOT)).replace("\\","/")
        old["closure_sha256"]=sha(artifact)
        old["inherited_T99_TextBuffer_source_specific_branch_complete"]=True
    if name=="T99:UFont::Serialize":
        # Independent UT99 v432 UFont full *read method*, not an
        # inferred closure from other UObject engines/profiles.
        # Actual compiled v432 Engine source loader field values:
        # 4 genuine Engine.u full glyph arrays and 62 genuine
        # UWindowFonts complete glyphs+TMap pairs+page texture refs.
        # 66 no-stack/no-tag UObject Super envelopes verified on disk.
        test="verify-ue1-o06-g5-t99-font-independent-full.php"
        ev=next((r for r in reversed(events)
            if r.get("test")==test and r.get("state")=="PASS"),None)
        assert ev is not None
        logfile=ROOT/ev["log"].replace("\\","/")
        assert sha(logfile)==ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/test)==ev["test_sha256"]
        assert scoped_source_current(ev["source_sha256"])
        summary=read(logfile)
        assert summary["ok"] and summary["checks"]==185
        assert summary["passed"]==185 and summary["review"]=="FULL_SOURCE_PROFILE_ONLY"
        assert summary["source_profile"]=="UT99-v432"
        assert summary["verified_native_4_existing_plus_62_new_Font_objects"]==66
        assert summary["new_complete_compiled_glyph_bytes"]==1200128
        assert summary["new_complete_compiled_map_bytes"]==185736
        assert summary["new_complete_compiled_map_pairs"]==46434
        assert summary["original_inherited_UObject_no_stack_no_tags"]==66
        assert summary["original_native_failure_cursor"]=="Got 4116, Expected 4112"
        assert summary["repeated_prior_font_native_CDB_runs"]==0
        assert summary["general_T99_UObject"]=="BLOCKED_INDEPENDENTLY"
        assert set(summary["seven_gates"])==set(criteria)
        assert all(v=="VERIFIED" for v in summary["seven_gates"].values())
        manifest=C/"UE1-O06-G5-T99-FONT-COMPILED-ALL-VALUES-REVIEW.json"
        artifact=C/"UE1-O06-G5-UFONT-SOURCE-PROFILE-FULL-ACCEPTANCE.json"
        assert manifest.is_file() and artifact.is_file()
        closure=read(artifact)
        assert closure["decision"]=="FULL" and closure["method"]==name
        assert closure["scope"]=="UT99_v432_T99_source_profile_package_reader_loading_only"
        assert scoped_source_current(closure["current_production_source_sha256"])
        assert closure["independent_final_acceptance_script_sha256"]==ev["test_sha256"]
        assert closure["independent_final_acceptance_log_sha256"]==ev["output_sha256"]
        assert closure["review_source_manifest_sha256"]==sha(manifest)
        assert closure["seven_verified_criteria"]==summary["seven_gates"]
        assert closure["compiled_original_native_new_invocations"]==2
        assert closure["prior_native_CDB_replays"]==0
        assert closure["general_T99_UObject_method_decision"]=="BLOCKED_SEPARATELY"
        assert entry["current"] and entry["pinned"]
        gates.update(summary["seven_gates"])
        entry["result"]="FULL"
        entry["reason"]=("Source-profile FULL T99-v432 UFont file-reading: "
            "66 genuine inherited no-stack no-tag envelopes, "
            "62 complete native UWindowFonts glyph+map+texture values "
            "plus 4 original native Engine fonts, real malformed "
            "CharRemap cursor parity, and independent 185/185 "
            "7-of-7 original compiled engine acceptance.")
        entry["blocker"]=None
        entry["next"]="T99 v432 Font complete; do not repeat original 66 fonts. Generic UObject and other source profiles remain separate."
        old["closure_artifact"]=str(artifact.relative_to(ROOT)).replace("\\","/")
        old["closure_sha256"]=sha(artifact)
        old["full_native_font_objects_compared"]=66
        old["full_native_font_glyph_bytes_compared"]=1200128
        old["full_native_font_remap_pairs_compared"]=46434
        old["method_scoped_inherited_super_closed"]=True
    if name=="T99:UObject::Serialize":
        events=[json.loads(line) for line in (C/"UE1-O06-TEST-EVENTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if line.strip()]
        method_test="verify-ue1-o06-g5-uobject-consolidated-acceptance.php"
        pass_event=next((item for item in reversed(events) if item.get("test")==method_test and item.get("state")=="PASS"),None)
        assert pass_event is not None,"Missing single UObject compiled/native verdict"
        log=ROOT/pass_event["log"].replace("\\","/")
        verifier=ROOT/"catalog"/"bin"/method_test
        assert sha(log)==pass_event["output_sha256"] and sha(verifier)==pass_event["test_sha256"]
        assert pass_event["source_sha256"]==reconciliation["previous_uobject_source_before_pointregion"]
        result=read(log)
        assert result["ok"] and result["passed"]==43 and result["checks"]==43
        assert result["full_method_acceptance"]=="BLOCKED"
        assert result["genuine_original_StateFrames"]==19
        assert result["native_compiled_loader_positive_StateFrames"]==14
        assert result["native_malformed_offsets_php_exact_error_matches"]==3
        assert result["native_compiled_reruns_this_verification"]==0
        assert reconciliation["source_uobject_positive_branch_equivalence"]
        assert reconciliation["previous_uobject_positive_source_sha256"]
        # New current-source 113/113 loaded property proof REUSES the prior
        # native engine export, original 43/43, and source scoped code hashes.
        tagtest="verify-ue1-o06-g5-uobject-tagged-native-acceptance.php"
        tagged=next((item for item in reversed(events)
            if item.get("test")==tagtest and item.get("state")=="PASS"),None)
        assert tagged is not None,"Missing new original 113/113 native tagged values"
        tagged_log=ROOT/tagged["log"].replace("\\","/")
        tagged_verifier=ROOT/"catalog"/"bin"/tagtest
        assert sha(tagged_log)==tagged["output_sha256"]
        assert sha(tagged_verifier)==tagged["test_sha256"]
        assert tagged["source_sha256"]==reconciliation["previous_source_before_native_tag_error_change"]
        t=read(tagged_log)
        assert t["ok"] and t["checks"]==37 and t["passed"]==37
        assert t["genuine_original_native_loaded_exports"]==33
        assert t["complete_saved_tagged_value_comparisons"]==113
        assert t["previous_compiled_StateFrame_checks_reused_not_rerun"]==43
        assert t["compiled_native_executable_replays"]==0
        assert t["independent_full_method_acceptance"]=="BLOCKED"
        assert reconciliation["source_region_pointregion_exact_codec_only"]
        # Latest source-v432 change is pinned to both modified readers
        # plus a REAL new compiled four-negative-case corpus. Do not replay
        # the 37/37 positive test or 43/43 StateFrame test.
        latest_test="verify-ue1-o06-g5-uobject-tag-error-consolidated.php"
        recent=next((item for item in reversed(events)
            if item.get("test")==latest_test and item.get("state")=="PASS"),None)
        assert recent is not None,"Missing newest compiled native PropertyTag acceptance"
        latest_log=ROOT/recent["log"].replace("\\","/")
        assert sha(latest_log)==recent["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/latest_test)==recent["test_sha256"]
        assert recent["source_sha256"]==reconciliation["previous_source_before_native_reference_change"]
        latest_result=read(latest_log)
        assert latest_result["ok"] and latest_result["checks"]==49
        assert latest_result["passed"]==49
        assert latest_result["native_tag_error_cases_compared"]==4
        assert latest_result["native_tag_error_cases_matching"]==4
        assert latest_result["all_native_tagged_positive_values_matched"]==113
        assert latest_result["original_native_loaded_exports_preserved"]==33
        assert latest_result["compiled_oracle_executable_replays"]==0
        assert latest_result["native_tagged_error_full_method_acceptance"]=="BLOCKED"
        assert reconciliation["source_native_tag_error_change_scoped"]
        # CURRENT changed-method full-decision test: six extra real compiled
        # v432 errors / skip outcomes plus all genuine loaded value refs.
        # Four earlier native tagged-error cases and 43 StateFrames are
        # reused by immutable prior evidence; NO new compiled invocation.
        current_test="verify-ue1-o06-g5-uobject-current-full-decision.php"
        ev=next((item for item in reversed(events)
            if item.get("test")==current_test and item.get("state")=="PASS"),None)
        assert ev is not None,"Missing current-source UObject consolidated method decision"
        current_log=ROOT/ev["log"].replace("\\","/")
        assert sha(current_log)==ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/current_test)==ev["test_sha256"]
        assert ev["source_sha256"]==reconciliation["previous_source_before_native_struct_str_size_change"]
        current_result=read(current_log)
        assert current_result["ok"] and current_result["checks"]==61
        assert current_result["passed"]==61
        assert current_result["previous_compiled_native_malformed_tag_cases_reused"]==4
        assert current_result["previous_compiled_StateFrame_evidence_reused"]==43
        assert current_result["new_compiled_native_reference_cases_reused"]==6
        assert current_result["real_native_reference_outcomes_matched"]==6
        assert current_result["fatal_archive_offset_outcomes_matched"]==4
        assert current_result["nonfatal_exact_skip_bytes_outcomes_matched"]==2
        assert current_result["current_source_compiled_loaded_values_matched"]==113
        assert current_result["current_source_compiled_loaded_exports"]==33
        assert current_result["new_compiled_engine_invocations"]==0
        assert current_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_reference_change_scoped"]
        # Latest actual compiled v432 Tag.Size corruption: existing resolved
        # Struct and FString SerializeItem consume native member lengths.
        # Current full-method decision uses four new engine runs only.
        sizetest="verify-ue1-o06-g5-uobject-serializeitem-size-decision.php"
        new_ev=next((item for item in reversed(events)
            if item.get("test")==sizetest and item.get("state")=="PASS"),None)
        assert new_ev is not None,"Missing actual compiled new v432 size decision"
        size_result_log=ROOT/new_ev["log"].replace("\\","/")
        assert sha(size_result_log)==new_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/sizetest)==new_ev["test_sha256"]
        assert new_ev["source_sha256"]==reconciliation["previous_source_before_native_dynamic_array_change"]
        size_result=read(size_result_log)
        assert size_result["ok"] and size_result["checks"]==50
        assert size_result["passed"]==50
        assert size_result["real_native_new_size_mutation_cases"]==4
        assert size_result["valid_struct_TagSize_mismatch_consumes_source_bytes"]==2
        assert size_result["valid_FString_TagSize_mismatch_consumes_source_bytes"]==2
        assert size_result["previous_native_reference_cases_SHA_reused"]==6
        assert size_result["previous_native_property_error_cases_SHA_reused"]==4
        assert size_result["previous_StateFrame_decision_checks_SHA_reused"]==43
        assert size_result["compiled_original_tags_current_source_matched"]==113
        assert size_result["compiled_original_exports_current_source_matched"]==33
        assert size_result["compiled_native_loader_reruns"]==0
        assert size_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_struct_str_size_change_scoped"]
        # Latest compiled Core v432 real dynamic array engine-generated archive.
        # Native count/inner-reference errors and exact cursor from one batch;
        # all previous genuine original loaded positives SHA-scoped, not rerun.
        array_test="verify-ue1-o06-g5-uobject-dynamic-array-decision.php"
        array_ev=next((item for item in reversed(events)
            if item.get("test")==array_test and item.get("state")=="PASS"),None)
        assert array_ev is not None,"Missing native dynamic array consolidated decision"
        array_log=ROOT/array_ev["log"].replace("\\","/")
        assert sha(array_log)==array_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/array_test)==array_ev["test_sha256"]
        assert array_ev["source_sha256"]==reconciliation["previous_source_before_native_nested_vector_change"]
        array_result=read(array_log)
        assert array_result["ok"] and array_result["checks"]==61
        assert array_result["passed"]==61
        assert array_result["new_compiled_dynamic_array_oracle_cases"]==6
        assert array_result["new_compiled_array_fatal_archive_cursors_matched"]==4
        assert array_result["new_compiled_array_valid_malicious_size_cases_matched"]==2
        assert array_result["compiled_native_original_three_array_values_matched"]==3
        assert array_result["native_original_113_loaded_property_values_prior_SHA_reused"]==113
        assert array_result["prior_StateFrame_checks_SHA_reused"]==43
        assert array_result["compiled_native_loader_reruns_in_verifier"]==0
        assert array_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_dynamic_array_change_scoped"]
        nested_test="verify-ue1-o06-g5-uobject-nested-vector-decision.php"
        nested_ev=next((item for item in reversed(events)
            if item.get("test")==nested_test and item.get("state")=="PASS"),None)
        assert nested_ev is not None,"Missing source-current actual compiled nested vector oracle"
        nested_log=ROOT/nested_ev["log"].replace("\\","/")
        assert sha(nested_log)==nested_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/nested_test)==nested_ev["test_sha256"]
        assert nested_ev["source_sha256"]==reconciliation["previous_source_before_native_prd_fstring_load_change"]
        nested_result=read(nested_log)
        assert nested_result["ok"] and nested_result["checks"]==36 and nested_result["passed"]==36
        assert nested_result["real_compiled_native_nested_struct_cases_matched"]==3
        assert nested_result["compiled_native_nested_vector_loaded_value_matches"]==1
        assert nested_result["compiled_native_nested_array_fatal_cursor_matches"]==2
        assert nested_result["actual_native_nested_struct_array_consumed_bytes"]==25
        assert nested_result["previous_six_dynamic_array_cases_SHA_reused"]==6
        assert nested_result["previous_genuine_original_TaggedValues_SHA_reused"]==113
        assert nested_result["previous_StateFrame_SHA_reused"]==43
        assert nested_result["compiled_native_engine_reruns_in_verifier"]==0
        assert nested_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_nested_vector_change_scoped"]
        # Genuine PRD UE1 archive samples + native compiled v432 nonempty
        # FString loader crash; distinguish serialized wire data and loaded.
        string_test="verify-ue1-o06-g5-uobject-prd-native-string-decision.php"
        string_ev=next((item for item in reversed(events)
            if item.get("test")==string_test and item.get("state")=="PASS"),None)
        assert string_ev is not None,"Missing changed-source native FString load verdict"
        string_log=ROOT/string_ev["log"].replace("\\","/")
        assert sha(string_log)==string_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/string_test)==string_ev["test_sha256"]
        assert string_ev["source_sha256"]==reconciliation["previous_source_before_v227_flag_interpretation_change"]
        string_result=read(string_log)
        assert string_result["ok"] and string_result["checks"]==37
        assert string_result["passed"]==37
        assert string_result["prd_original_staged_u_unr"]==239
        assert string_result["prd_original_camera_StateFrames_static_decoded"]==433
        assert string_result["prd_source_native_FSpark_records_decoded"]==4573
        assert string_result["real_v432_saved_nonempty_FString_elements_source_decoded"]==2
        assert string_result["compiled_v432_nonempty_string_load_status"]=="native_access_violation"
        assert string_result["compiled_Core_native_load_success"] is False
        assert string_result["current_production_string_native_loaded_value_parity"]=="NOT_VERIFIED"
        assert string_result["previous_compiled_native_nested_vector_cases_SHA_reused"]==3
        assert string_result["previous_113_genuine_Entry_native_tagged_values_SHA_reused"]==113
        assert string_result["compiled_native_reruns_in_acceptance"]==0
        assert string_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_prd_fstring_load_change_scoped"]
        # New real original PRD Unreal v61 map (not tool-generated):
        # compiled v432 runtime class/object identity 308/312; precisely
        # four original Camera objects not loaded. All 308 PHP tag archives
        # and 184 native source-specific tails complete. Zero oracle replay.
        v61_test="verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php"
        v61_ev=next((item for item in reversed(events)
            if item.get("test")==v61_test and item.get("state")=="PASS"),None)
        assert v61_ev is not None,"Missing compiled v432 genuine PRD v61 native object oracle"
        v61_log=ROOT/v61_ev["log"].replace("\\","/")
        assert sha(v61_log)==v61_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/v61_test)==v61_ev["test_sha256"]
        assert v61_ev["source_sha256"]==reconciliation["previous_source_before_v227_flag_interpretation_change"]
        v61_result=read(v61_log)
        assert v61_result["ok"] and v61_result["checks"]==24 and v61_result["passed"]==24
        assert v61_result["real_prd_v61_exports"]==312
        assert v61_result["compiled_native_v432_loaded_objects"]==308
        assert v61_result["exact_native_class_name_matches"]==308
        assert v61_result["source_archives_for_native_loaded"]==308
        assert v61_result["source_native_tails_complete"]==184
        assert v61_result["native_uninstantiated_Camera"]==4
        assert v61_result["native_exported_property_lines_not_individually_compared"]==8515
        assert v61_result["native_oracle_replays_in_acceptance"]==0
        assert v61_result["full_method_decision"]=="BLOCKED"
        # Source-level v227 flag interpretation is verified separately.
        flag_test="verify-ue1-o06-g5-uobject-v227-source-flag-decision.php"
        flag_ev=next((item for item in reversed(events)
            if item.get("test")==flag_test and item.get("state")=="PASS"),None)
        assert flag_ev is not None
        flag_log=ROOT/flag_ev["log"].replace("\\","/")
        assert sha(flag_log)==flag_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/flag_test)==flag_ev["test_sha256"]
        assert flag_ev["source_sha256"]==reconciliation["previous_source_before_genuine_v227_script_change"]
        flag_result=read(flag_log)
        assert flag_result["ok"] and flag_result["checks"]==30
        assert flag_result["passed"]==30
        assert flag_result["v1200_v432_shared_archive_frame_versions"]=="60..61"
        assert flag_result["v227_loaded_DebugSerialize_bit"]=="never fabricated"
        assert flag_result["old_compiled_PRD_v61_load_exact_identity_count_SHA_reused"]==308
        assert flag_result["native_engine_reruns_in_verifier"]==0
        assert flag_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_v227_flag_interpretation_change_scoped"]
        # Separate original PRD 69/licensee227 source-defined script codec
        # correction (27 class exports recovered, 399 ordinary streams)
        # has one current-source acceptance and zero native engine replay.
        script_test="verify-ue1-o06-c227-genuine-script-source-decision.php"
        script_ev=next((item for item in reversed(events)
            if item.get("test")==script_test and item.get("state")=="PASS"),None)
        assert script_ev is not None
        script_log=ROOT/script_ev["log"].replace("\\","/")
        assert sha(script_log)==script_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/script_test)==script_ev["test_sha256"]
        assert script_ev["source_sha256"]==reconciliation["previous_source_before_v54_v1200_FSphere_change"]
        script_result=read(script_log)
        assert script_result["ok"] and script_result["checks"]==24
        assert script_result["passed"]==24
        assert script_result["genuine_original_licensee_227_packages"]==3
        assert script_result["real_original_exports"]==426
        assert script_result["original_object_tagged_streams_decoded"]==399
        assert script_result["original_UClass_export_streams_decoded"]==27
        assert script_result["current_original_class_export_parser_errors"]==0
        assert script_result["compiled_engine_reruns_in_verifier"]==0
        assert script_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_genuine_v227_script_opcode_change_scoped"]
        # One original v54 Unreal Gold map exposed a v1200 FSphere
        # wire-size error, now source-corrected. Existing v432 compiled
        # engine results were reused and never rerun.
        sphere_test="verify-ue1-o06-c98-v54-real-model-sphere-source-decision.php"
        sphere_ev=next((item for item in reversed(events)
            if item.get("test")==sphere_test and item.get("state")=="PASS"),None)
        assert sphere_ev is not None
        sphere_log=ROOT/sphere_ev["log"].replace("\\","/")
        assert sha(sphere_log)==sphere_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/sphere_test)==sphere_ev["test_sha256"]
        assert sphere_ev["source_sha256"]==reconciliation["previous_source_before_Camera_loader_skip_change"]
        sphere_result=read(sphere_log)
        assert sphere_result["ok"] and sphere_result["checks"]==31
        assert sphere_result["passed"]==31
        assert sphere_result["genuine_PRD_original_v54_export_count"]==2667
        assert sphere_result["real_legacy_UModel_complete_after_fix"]==299
        assert sphere_result["real_legacy_UModel_previously_failed_or_extended"]==299
        assert sphere_result["UT99_v432_existing_native_oracles_rerun"]==0
        assert sphere_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_genuine_v54_v1200_FSphere_change_scoped"]
        # Camera was NOT an unverified native-positive requirement:
        # both Epic native linkers intentionally exclude it in scoped
        # versions. Reuse the 4+5 prior genuine compiled v432 omissions.
        camera_test="verify-ue1-o06-g5-camera-source-reachability-decision.php"
        camera_ev=next((item for item in reversed(events)
            if item.get("test")==camera_test and item.get("state")=="PASS"),None)
        assert camera_ev is not None
        camera_log=ROOT/camera_ev["log"].replace("\\","/")
        assert sha(camera_log)==camera_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/camera_test)==camera_ev["test_sha256"]
        assert camera_ev["source_sha256"]==reconciliation["previous_source_before_v227_export_guard"]
        camera_result=read(camera_log)
        assert camera_result["ok"] and camera_result["checks"]==69
        assert camera_result["passed"]==69
        assert camera_result["genuine_source_excluded_Camera_exports"]==14
        assert camera_result["unrelated_original_exports_not_falsely_skipped"]==15
        assert camera_result["native_compiled_v432_4_plus_5_Camera_exclusions_sha_reused"]==9
        assert camera_result["camera_native_CreateExport_criterion"]=="CLOSED_SOURCE_DEFINED_EXCLUSION"
        assert camera_result["compiled_native_oracle_replays"]==0
        assert camera_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_Camera_loader_skip_change_scoped"]
        # v227 FObjectExport adds a persistent ArchetypeIndex. Its compiled
        # native archive implementation is not in public Epic source:
        # never infer the wire format or accept a flagged export as zero.
        archetype_test="verify-ue1-o06-c227-archetype-native-operator-guard.php"
        archetype_ev=next((item for item in reversed(events)
            if item.get("test")==archetype_test and item.get("state")=="PASS"),None)
        assert archetype_ev is not None
        archetype_log=ROOT/archetype_ev["log"].replace("\\","/")
        assert sha(archetype_log)==archetype_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/archetype_test)==archetype_ev["test_sha256"]
        assert archetype_ev["source_sha256"]==reconciliation["previous_source_before_audio_array_dispatch"]
        archetype_result=read(archetype_log)
        assert archetype_result["ok"] and archetype_result["checks"]==24
        assert archetype_result["passed"]==24
        assert archetype_result["genuine_export_rows_unmodified"]==426
        assert archetype_result["real_RF_HasArchtype_positives"]==0
        assert archetype_result["dev_only_single_flag_mutations_rejected"]==3
        assert archetype_result["compiled_native_replays"]==0
        assert archetype_result["source_native_archetype_serialize"]=="UNVERIFIED"
        assert archetype_result["full_method_decision"]=="BLOCKED"
        assert reconciliation["source_native_v227_export_archetype_guard_scoped"]
        # Shared native audio source chooses TArray for Unreal v1.200
        # and TLazyArray for UT99/v227. Its new compiled-independent
        # acceptance covers 35 SHA-pinned genuine original audio exports.
        audio_test="verify-ue1-o06-g5-native-audio-source-array-decision.php"
        audio_ev=next((item for item in reversed(events)
            if item.get("test")==audio_test and item.get("state")=="PASS"),None)
        assert audio_ev is not None
        audio_log=ROOT/audio_ev["log"].replace("\\","/")
        assert sha(audio_log)==audio_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/audio_test)==audio_ev["test_sha256"]
        assert audio_ev["source_sha256"]==reconciliation["previous_source_before_audio_loaded_malformed_change"]
        audio_result=read(audio_log)
        assert audio_result["ok"] and audio_result["checks"]==176
        assert audio_result["passed"]==176
        assert audio_result["genuine_real_original_audio_exports"]==35
        assert audio_result["v1200_TArray_audio_records"]==2
        assert audio_result["v227_TLazyArray_audio_records"]==33
        assert audio_result["compilation_engine_reruns"]==0
        assert audio_result["method_full_decision"]=="BLOCKED"
        assert reconciliation["source_native_audio_array_type_dispatch_scoped"]
        # Original PRD v61 compiled v432 engine exported 8,515
        # runtime property text lines. The previously unreviewed 926
        # SAVED tags have now been reconciled: 802 exact typed native
        # loaded values, 124 transient XLevel omitted by UProperty::Port
        # under exact original Epic source. No native engine replay.
        v61_tags_test="verify-ue1-o06-g5-uobject-original-v61-native-saved-values.php"
        v61_tags_ev=next((item for item in reversed(events)
            if item.get("test")==v61_tags_test and item.get("state")=="PASS"),None)
        assert v61_tags_ev is not None
        v61_tags_log=ROOT/v61_tags_ev["log"].replace("\\","/")
        assert sha(v61_tags_log)==v61_tags_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/v61_tags_test)==v61_tags_ev["test_sha256"]
        assert scoped_source_current(v61_tags_ev["source_sha256"])
        v61_tag_values=read(v61_tags_log)
        assert v61_tag_values["ok"] and v61_tag_values["checks"]==30
        assert v61_tag_values["passed"]==30
        assert v61_tag_values["native_original_v61_export_objects"]==308
        assert v61_tag_values["saved_tag_properties"]==926
        assert v61_tag_values["loaded_native_value_matches"]==802
        assert v61_tag_values["source_defined_XLevel_text_exclusions"]==124
        assert v61_tag_values["native_value_mismatches"]==0
        assert v61_tag_values["unaccounted_saved_tags"]==0
        assert v61_tag_values["compiled_engine_replays"]==0
        assert v61_tag_values["T99_UObject_independent_FULL"]=="BLOCKED"
        old["compiled_genuine_original_v61_loaded_saved_values_verified"]=802
        old["compiled_genuine_original_v61_source_defined_transient_XLevel_export_text_exclusions"]=124
        old["compiled_genuine_original_v61_saved_tag_value_mismatches"]=0
        old["compiled_genuine_original_v61_all_saved_tags_accounted"]=926
        # The 124 XLevel tags excluded by UObject::ExportProperties
        # are actual native non-null Actor.XLevel pointers, NOT merely
        # an inferred text exclusion: check the true compiled v432
        # AActor member and compare entire object paths vs export IDs.
        xlevel_test="verify-ue1-o06-g5-uobject-v61-124-native-XLevel-pointers.php"
        xlevel_ev=next((item for item in reversed(events)
            if item.get("test")==xlevel_test and item.get("state")=="PASS"),None)
        assert xlevel_ev is not None
        xlevel_log=ROOT/xlevel_ev["log"].replace("\\","/")
        assert sha(xlevel_log)==xlevel_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/xlevel_test)==xlevel_ev["test_sha256"]
        assert scoped_source_current(xlevel_ev["source_sha256"])
        xlevel_values=read(xlevel_log)
        assert xlevel_values["ok"] and xlevel_values["checks"]==25
        assert xlevel_values["passed"]==25
        assert xlevel_values["native_compiled_actor_XLevel_pointers"]==124
        assert xlevel_values["full_class_qualified_export_references_matched"]==124
        assert xlevel_values["native_positive_mismatches"]==0
        assert xlevel_values["genuine_v61_total_saved_values_compared"]==926
        assert xlevel_values["native_compiled_invocations"]==1
        assert xlevel_values["global_T99_UObject_status"]=="BLOCKED_OTHER_VERSION_AND_METHOD_GATES"
        old["compiled_genuine_original_v61_direct_native_XLevel_pointer_values_verified"]=124
        old["compiled_genuine_original_v61_total_native_saved_values_verified"]=926
        old["compiled_genuine_original_v61_all_saved_values_complete"]=True
        # This closes only the v61 ORIGINAL, not every other class/
        # version/error path of UObject::Serialize.
        # These 113 values are a fully validated genuine v68 package slice,
        # NOT enough to mark every UObject serializer value path VERIFIED.
        old["compiled_genuine_original_loaded_tags_verified"]=113
        eof_event=next((e for e in reversed(events)
            if e.get("test")=="verify-ue1-o06-g5-uobject-native-array-physical-eof.php"
               and e.get("state")=="PASS"),None)
        assert eof_event is not None
        eof_output=ROOT/eof_event["log"].replace("\\","/")
        assert sha(eof_output)==eof_event["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/"verify-ue1-o06-g5-uobject-native-array-physical-eof.php")==eof_event["test_sha256"]
        assert reconciliation["source_native_array_physical_EOF_scoped"]
        assert eof_event["source_sha256"]==reconciliation["previous_source_before_native_nested_FString_EOF_change"]
        eof_result=read(eof_output)
        assert eof_result["ok"] and eof_result["checks"]==21 and eof_result["passed"]==21
        assert eof_result["native_linker_failure_absolute_offset"]==308
        assert eof_result["PHP_diagnostic_native_source_origin"]=="ULinkerLoad::Serialize"
        assert eof_result["native_binary_reruns_in_verifier"]==0
        assert eof_result["global_UObject_method_full"]=="BLOCKED_OTHER_SOURCE_VARIANTS"
        old["compiled_native_UArrayProperty_physical_EOF_error_current_source"]=True
        old["compiled_native_UArrayProperty_physical_EOF_cursor"]=308
        old["compiled_native_UArrayProperty_physical_EOF_decision_checks"]=21
        old["source_local_UArrayProperty_count_guard_regression_fixed"]=True
        # One separate, current-source, original compiled v432 malformed
        # nested FString case; no original engine execution during replay.
        nested_test="verify-ue1-o06-g5-uobject-nested-fstring-physical-eof.php"
        nested_ev=next((e for e in reversed(events)
            if e.get("test")==nested_test and e.get("state")=="PASS"),None)
        assert nested_ev is not None
        nested_log=ROOT/nested_ev["log"].replace("\\","/")
        assert reconciliation["source_native_nested_FString_physical_EOF_scoped"]
        assert reconciliation["source_native_mesh_seek_and_C98_FSphere_scoped"]
        assert nested_ev["source_sha256"]==reconciliation["previous_source_before_mesh_fix"]
        assert sha(nested_log)==nested_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/nested_test)==nested_ev["test_sha256"]
        nested_result=read(nested_log)
        assert nested_result["ok"] and nested_result["checks"]==25
        assert nested_result["passed"]==25 and nested_result["failed"]==[]
        assert nested_result["native_unchanged_compiled_DLL"] is True
        assert nested_result["native_conditioned_allocator_origin_explicit"] is True
        assert nested_result["native_mutation_physical_EOF_byte"]==308
        assert nested_result["production_physical_EOF_cursor"]==308
        assert nested_result["native_mutation_exception_origin"]=="ULinkerLoad::Serialize <- FString<<"
        assert nested_result["stock_native_loaded_FString_parity"]=="NOT_VERIFIED"
        assert nested_result["general_T99_UObject_METHOD"]=="BLOCKED"
        assert nested_result["native_engine_reruns_in_verifier"]==0
        old["compiled_native_nested_FString_physical_EOF_current_source"]=True
        old["compiled_native_nested_FString_physical_EOF_cursor"]=308
        old["compiled_native_nested_FString_EOF_decision_checks"]=25
        nested_manifest=C/"UE1-O06-G5-T99-UOBJECT-NESTED-FSTRING-PHYSICAL-EOF.json"
        assert nested_manifest.is_file()
        nested_proof=read(nested_manifest)
        assert nested_proof["method"]==name
        assert nested_proof["method_global_full"]=="BLOCKED"
        assert nested_proof["source_hunk_isolated"] is True
        assert nested_proof["source_fingerprint_after"]==reconciliation["previous_source_before_mesh_fix"]
        assert nested_proof["final_acceptance"]["stdout_sha256"]==nested_ev["output_sha256"]
        assert nested_proof["final_acceptance"]["test_sha256"]==nested_ev["test_sha256"]
        assert nested_proof["final_acceptance"]["passed"]==25
        assert nested_proof["old_native_loader_oracles_repeated"]==0
        assert nested_proof["new_native_engine_invocations"]==1
        for info in nested_proof["proof_SHA"].values():
            assert sha(Path(info["path"]))==info["sha256"]
        old["compiled_native_nested_FString_evidence_artifact"]=str(nested_manifest.relative_to(ROOT)).replace("\\","/")
        old["compiled_native_nested_FString_evidence_SHA"]=sha(nested_manifest)
        old["compiled_native_malformed_tag_cases_valid"]=4
        old["compiled_native_malformed_tag_outcomes_current_source"]=True
        old["compiled_native_reference_array_struct_cases_valid"]=6
        old["compiled_native_fatal_reference_archive_offsets_valid"]=4
        old["compiled_native_array_struct_skip_cases_valid"]=2
        old["compiled_current_full_method_decision_checks"]=30
        old["source_equivalent_v1200_v432_modern_frame_wire_no_native_rerun"]=True
        old["v227_pendingdelete_flag_annotation_corrected"]=True
        old["genuine_licensed_v227_original_package_exports"]=426
        old["v227_original_UClass_scripts_source_decoded"]=27
        old["v227_original_UClass_prior_source_failures_corrected"]=11
        old["v227_real_source_archive_error_count_after_opcode_fix"]=0
        old["genuine_original_v54_epic_v1200_exports_source_parsed"]=2667
        old["genuine_original_v54_UModel_full_native_archives"]=299
        old["genuine_original_v54_UModel_previously_broken"]=299
        old["v1200_FSPhere_inherited_FVector12_compiled_v432_untouched"]=True
        old["epic_source_native_loader_camera_reachability"]="CLOSED_EXCLUDED_BY_CREATEEXPORT"
        old["genuine_Camera_archived_exports_not_instantiated"]=14
        old["v432_Camera_native_original_omissions_sha_reused"]=9
        old["v1200_v54_Camera_not_instantiated_by_source"]=5
        old["remaining_Camera_positive_native_oracle_requirement"]=False
        old["source_audio_array_type_dispatch"]="C98_TArray_UT99_and_C227_TLazyArray"
        old["genuine_source_parsed_audio_exports"]=35
        old["v227_FObjectExport_ArchetypeIndex_native_operator"]="UNAVAILABLE"
        old["v227_genuine_original_exports_without_RF_HasArchtype"]=426
        old["v227_source_flagged_ArchetypeIndex_export_guard"]="REFUSES_UNVERIFIED_WIRE"
        old["original_PRD_v61_compiled_native_loaded_object_identities"]=308
        old["original_PRD_v61_compiled_loader_native_uninstantiated_Camera"]=4
        old["original_PRD_v61_loaded_tagged_streams_current_source_decoded"]=308
        old["original_PRD_v61_native_class_tails_source_complete"]=184
        old["original_PRD_v61_native_export_text_lines_including_defaults"]=8515
        old["original_PRD_v61_native_saved_value_fields_current_source_matched"]=802
        old["prd_original_verified_u_unr_files_staged"]=239
        old["prd_original_camera_stateframes_source_parsed"]=433
        old["prd_source_native_firetexture_fspark_records"]=4573
        # Distinct controlled-allocator diagnostic: the SAME unmodified
        # original compiled v432 Core.dll loads a stock-generated original
        # package only when the disposable harness zeros allocated memory.
        # FArray::Add does not initialize FString inner elements; stock
        # native loader faults on 0xBAADF00D. Never certify stock runtime
        # loaded value parity from a modified allocation policy.
        string_test="verify-ue1-o06-g5-uobject-fstring-conditional-native.php"
        string_ev=next((e for e in reversed(events)
            if e.get("test")==string_test and e.get("state")=="PASS"),None)
        assert string_ev is not None
        string_log=ROOT/string_ev["log"].replace("\\","/")
        assert sha(string_log)==string_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/string_test)==string_ev["test_sha256"]
        assert reconciliation["source_native_nested_FString_physical_EOF_scoped"]
        assert string_ev["source_sha256"]==reconciliation["previous_source_before_native_nested_FString_EOF_change"]
        string_decision=read(string_log)
        assert string_decision["ok"] and string_decision["checks"]==34
        assert string_decision["passed"]==34
        assert string_decision["native_original_v432_DLL_loaded_under_zero_allocator"] is True
        assert string_decision["original_archive_nested_strings_equal_diagnostic_native"]==2
        assert string_decision["original_archive_nested_vector_scalars_equal_diagnostic_native"]==6
        assert string_decision["stock_allocator_native_loader_failure_captured"] is True
        assert string_decision["stock_allocator_FString_loaded_value_parity"]=="NOT_VERIFIED"
        assert string_decision["T99_UObject_method_full"]=="BLOCKED"
        assert string_decision["real_native_executions_repeated_in_this_test"]==0
        string_manifest=C/"UE1-O06-G5-T99-UOBJECT-FSTRING-ALLOCATOR-CONDITIONAL.json"
        assert string_manifest.is_file()
        string_data=read(string_manifest)
        assert string_data["all_archive_diagnostic_memory_values_match"] is True
        assert string_data["cpp_native_DLL_untouched"] is True
        assert string_data["stock_allocator_observed_result"]=="NATIVE_FAILED_INVALID_POINTER_0xBAADF00D_WITHOUT_LOADED_VALUE"
        assert string_data["T99_UObject_GLOBAL_FULL"]=="BLOCKED"
        for group in ("native_original_Epic_source_SHA","validation_artifacts_SHA"):
            for info in string_data[group].values():
                historic_php=ROOT/"catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php"
                if (Path(info["path"]).resolve()==historic_php.resolve()
                    and info["sha256"]!=sha(historic_php)):
                    # This conditional native proof was made BEFORE the
                    # later nested-FString physical EOF repair. Verify its
                    # actual committed PHP source, never substitute the
                    # current file's different SHA for historical evidence.
                    assert reconciliation["source_native_nested_FString_physical_EOF_scoped"]
                    prior=subprocess.check_output(["git","-C",str(ROOT),"show",
                        "635d6bd9:catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php"])
                    assert hashlib.sha256(prior).hexdigest()==info["sha256"]
                else:
                    assert sha(Path(info["path"]))==info["sha256"]
        old["compiled_v432_native_nonempty_FString_load_failed_in_fixture"]=True
        old["source_generated_original_nonempty_FString_archive_text_decode_verified"]=2
        old["zero_initialized_allocator_actual_compiled_FString_loaded_values_verified"]=2
        old["zero_initialized_allocator_actual_compiled_nested_vector_scalars_verified"]=6
        old["zero_initialized_allocator_test_source_SHA_pinned"]=True
        old["compiled_v432_stock_FString_heap_materialization_risk"]="UNINITIALIZED_ARRAY_ELEMENTS"
        old["native_FString_loaded_value_parity"]="NOT_VERIFIED"
        old["compiled_native_nested_vector_loaded_values"]=2
        old["compiled_native_nested_vector_invalid_count_offsets_matched"]=2
        old["compiled_native_dynamic_array_proven_int_name_object"]=3
        old["compiled_native_dynamic_array_fatal_cursors"]=4
        old["compiled_native_dynamic_array_size_override_cases"]=2
        old["compiled_native_valid_struct_malformed_size_cases"]=2
        old["compiled_native_valid_FString_malformed_size_cases"]=2
        old["compiled_genuine_original_exports_compared"]=33
        old["compiled_original_14_StateFrames_prior_verified"]=True
        gates["current_acceptance_tests"]="VERIFIED"
    if name=="T99:UAnimation::Serialize":
        whole_test="verify-ue1-o06-g5-t99-animation-whole-native.php"
        events=[json.loads(line) for line in (C/"UE1-O06-TEST-EVENTS.jsonl").read_text(encoding="utf-8-sig").splitlines() if line.strip()]
        whole_ev=next((e for e in reversed(events)
            if e.get("test")==whole_test and e.get("state")=="PASS"),None)
        assert whole_ev is not None
        assert whole_ev["source_sha256"]==reconciliation["current_source_sha256"]
        assert sha(ROOT/"catalog"/"bin"/whole_test)==whole_ev["test_sha256"]
        whole_log=ROOT/whole_ev["log"].replace("\\","/")
        assert sha(whole_log)==whole_ev["output_sha256"]
        whole=read(whole_log)
        assert whole["ok"] and whole["passed"]==whole["checks"]==212
        assert whole["native_source_compared_loaded_value_checks"]==3694
        assert whole["native_source_compared_record_lines"]==3667
        assert whole["actual_native_nested_array_bytes"]==540836
        assert whole["compiled_original_native_rejected_mutations"]==8
        assert whole["original_inherited_empty_UObject_envelopes"]==3
        assert not whole["native_engine_reexecuted_by_acceptance"]
        assert whole["full_method_verdict"]=="BLOCKED"
        artifact=C/"UE1-O06-G5-T99-ANIMATION-ONE-METHOD-NATIVE.json"
        original=read(artifact)
        assert original["verdict"]=="BLOCKED"
        assert not original["negative_result"]["byte_exact_error_cursor_verified"]
        assert len(original["native_cases"])==3 and len(original["negative_cases"])==8
        rebuild=original["native_rebuild_revalidation"]
        assert rebuild["old_executable_sha256"]=="cecc5a0b7354642848229ee29387ee5df9fbef1ac8df24eddbbf4b1e54786adc"
        assert rebuild["new_executable_sha256"]==original["compiled_probe"]["executable"]["sha256"]
        assert len(rebuild["current_rebuilt_native_case_logs"])==11
        assert rebuild["result"]=="11_OF_11_SAME_LOADED_RECORD_TEXT_AND_NATIVE_EXCEPTION_DISPOSITION"
        cursor_test="verify-ue1-o06-g5-t99-animation-native-error-cursors.php"
        cursor_ev=next((e for e in reversed(events)
            if e.get("test")==cursor_test and e.get("state")=="PASS"),None)
        assert cursor_ev is not None
        assert cursor_ev["source_sha256"]==reconciliation["current_source_sha256"]
        cursor_log=ROOT/cursor_ev["log"].replace("\\","/")
        assert sha(cursor_log)==cursor_ev["output_sha256"]
        assert sha(ROOT/"catalog"/"bin"/cursor_test)==cursor_ev["test_sha256"]
        assert reconciliation["source_native_animation_exact_error_cursors_scoped"]
        cursor=read(cursor_log)
        assert cursor["ok"] and cursor["checks"]==cursor["passed"]==91
        assert cursor["original_native_reexecutions_in_verifier"]==0
        assert cursor["native_cdb_confirmed_cursors"]==cursor["production_reported_cursors"]
        assert cursor["native_cdb_confirmed_cursors"]=={
            "d00d-invalid-bone-name":153323,
            "EcoTerror-invalid-bone-name":34393,
            "EcoTerror-invalid-sequence-name":593470,
            "EcoTerror-negative-animtrack-count":899657,
            "EcoTerror-negative-keyquat-count":899657,
            "table-negative-refbones-count":413388}
        cursor_artifact=C/"UE1-O06-G5-T99-ANIMATION-NATIVE-ERROR-CURSORS.json"
        assert len(read(cursor_artifact)["exact_cursors"])==6
        gates["complete_native_field_values_and_references"]="VERIFIED"
        gates["archive_bytes_and_consumption"]="VERIFIED"
        gates["current_acceptance_tests"]="VERIFIED"
        # The method's source-defined FName errors and negative nested TArray
        # branches now match original compiled first-chance cursor evidence.
        # Do not elevate inherited/version variants or independent acceptance.
        gates["malformed_input_error_semantics"]="VERIFIED"
        gates["inherited_and_version_variant_semantics"]="OPEN"
        gates["independent_full_method_review"]="OPEN"
        old["compiled_v432_six_native_exception_cursors_verified"]=True
        old["native_v432_cursor_compiled_manifest_sha256"]=sha(cursor_artifact)
        old["native_v432_three_original_animation_full_member_oracle"]="VERIFIED"
        old["native_v432_all_70_moves_record_values_checked"]=3694
        old["native_v432_all_nested_key_array_bytes_checked"]=540836
        old["native_v432_distinct_malformed_rejection_agreements"]=8
        old["native_v432_malformed_error_cursor_status"]="SIX_COMPILED_EXACT_CURSOR_PARITY_VERIFIED"
        old["native_v432_whole_animation_evidence_manifest"]="catalog/compliance/"+artifact.name
        old["native_v432_whole_animation_evidence_SHA256"]=sha(artifact)
    assert entry["result"] in ("FULL","BLOCKED","FAILED")
    if entry["result"]=="FULL":
        assert set(gates)==set(criteria)
        assert all(v=="VERIFIED" for v in gates.values()),name
        assert old["closure_artifact"] and old["closure_sha256"]
        assert sha(ROOT/old["closure_artifact"])==old["closure_sha256"]
    else:
        assert any(v!="VERIFIED" for v in gates.values())
        assert old["closure_artifact"] is None
        assert old["closure_sha256"] is None
    old["criteria"]=gates
    old["decision"]=entry["result"]
    old["decisive_blocker"]=entry["blocker"]
    old["next_action"]=entry["next"]
    old["reason"]=entry["reason"]
    old["last_consolidated_review"]=(
        "FINAL_7_of_7_source_scoped_TextBuffer_302_checks" if name=="T99:UTextBuffer::Serialize"
        else "FINAL_7_of_7_source_scoped_Font_185_checks" if name=="T99:UFont::Serialize"
        else "current_native_dynamic_count_EOF_21_and_nested_FString_EOF_25_checks_SHA" if name=="T99:UObject::Serialize"
        else "FINAL_7_of_7_source_scoped_audio_inherited_PASS63" if name in ("T99:USound::Serialize","T99:UMusic::Serialize")
        else "whole_v432_native_full_values_3694_and_6_exact_malformed_native_cursors_91_checks_2_remaining_gates" if name=="T99:UAnimation::Serialize"
        else "existing_sha_pinned_evidence_only_no_new_tests")
    decisions[name]={
      "decision":entry["result"],"gate_results":gates,
      "closure_artifact":old["closure_artifact"],"closure_sha256":old["closure_sha256"],
      "existing_native_loaded_valid":evidence["native_loaded_evidence_current"],
      "existing_native_proof_current_global_fingerprint":entry["current"],
      "existing_export_slice_valid":evidence["native_slice_evidence_valid"],
      "decisive_blocker":entry["blocker"],
      "reason":entry["reason"],"next_action":entry["next"],
      "uobject_original_v61_saved_tag_fields":(
        {"actual_runtime_text_value_matches":802,
         "transient_text_excluded_by_Epic_Port":124,
         "total_archived_tags_accounted":926,
         "mismatched_fields":0}
        if name=="T99:UObject::Serialize" else None),
      "camera_native_loader_criterion":(
        "CLOSED_EPIC_SOURCE_DEFINED_CREATEEXPORT_EXCLUSION"
        if name=="T99:UObject::Serialize" else "NOT_APPLICABLE"),
      "uobject_native_dynamic_array_physical_EOF":(
        {"real_compiled_v432_fatal_cursor":308,
         "current_PHP_matches_native_loader_origin":True,
         "one_new_focused_SHA_verified_checks":21,
         "new_source_edit_isolated_without_prior_native_reruns":
            reconciliation["source_native_array_physical_EOF_scoped"]}
        if name=="T99:UObject::Serialize" else None),
      "new_native_tests_executed":(3 if name=="T99:UObject::Serialize" else 9 if name in ("T99:USound::Serialize","T99:UMusic::Serialize") else 11 if name=="T99:UAnimation::Serialize" else 0),
       "new_test_runs_executed":18 if name=="T99:UObject::Serialize" else 2 if name in ("T99:USound::Serialize","T99:UMusic::Serialize","T99:UTextBuffer::Serialize","T99:UFont::Serialize") else 2 if name=="T99:UAnimation::Serialize" else 0,
    }
report={
 "schema":1,
 "policy":"Exactly one FINAL, SHA-current compiled-method acceptance decision per method. No further partial proof tests: only address listed decisive blocker, then run one complete acceptance test for the affected method. No automatic FULL from prior partial checks.",
 "source_fingerprint":reconciliation["current_source_sha256"],
 "method_count":len(decisions),
 "fully_epic_verified":sum(d["decision"]=="FULL" for d in decisions.values()),
 "failed":sum(d["decision"]=="FAILED" for d in decisions.values()),
 "blocked":sum(d["decision"]=="BLOCKED" for d in decisions.values()),
  "new_oracle_runs":44,"new_test_runs":26,
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
    item["acceptance_criteria"]=result["gate_results"]
    item["acceptance_criteria_met"]=sum(v=="VERIFIED" for v in result["gate_results"].values())
    item["closure_artifact_verified"]=(result["decision"]=="FULL")
    item["closure_artifact"]=result["closure_artifact"]
    item["closure_sha256"]=result["closure_sha256"]
acceptance["failed_method_decisions"]=report["failed"]
acceptance["blocked_method_decisions"]=report["blocked"]
acceptance["distinct_fully_accepted_methods"]=report["fully_epic_verified"]
acceptance["target_method_acceptance_completed"]=report["fully_epic_verified"]
if report["fully_epic_verified"]>0:
    acceptance["classification"]="DISCOVERY_PLUS_INDEPENDENT_SHA_VERIFIED_SOURCE_PROFILE_CLOSURES"
acceptance_path.write_text(json.dumps(acceptance,ensure_ascii=False,indent=2)+"\n",encoding="utf-8",newline="\n")
print(json.dumps({"report":str(OUT.relative_to(ROOT)).replace("\\","/"),
 "methods":len(decisions),"FULL":report["fully_epic_verified"],"FAILED":report["failed"],"BLOCKED":report["blocked"],
 "new_test_runs":report["new_test_runs"],
 "failed_methods":[k for k,v in decisions.items() if v["decision"]=="FAILED"],
 "first_prerequisite":"T99:UObject::Serialize"},indent=2))
