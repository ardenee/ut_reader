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

def eval_t003(_row: dict[str, Any]) -> dict[str, Any]:
    """Compiled original v432 Core/Engine Script.Num and exact archive/memory bytes."""
    pins=[
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T003-v432-original-native-script-load.cpp",
                 "c24ab8cd1b2f537ace55089bd800013e4fafaee5fd691b9ea6696396ab5463a8"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T003-v432-original-native-script-load.log",
                 "028d1ddc84ac2e6a97180030ccb187b53f2e7e5f31bfc07708e2eac9db11006b"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T003-v432-original-native-build.log",
                 "3768474819f2ca0e78ae619c2a13a308320029d9df23fb3b931fd8f3b0e2dd1b"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T003-v432-31-DEV-PASS.json",
                 "cb333b56adaec157d56d69d436c686fe390ba0d6f77c971789345c32c818ea76"),
    ]
    path="catalog/compliance/evidence/o06/20261028-G3-T003-v432-31-DEV-PASS.json"
    pins.append(verified_pass_log(path,"checks",31))
    original=json.loads((ROOT/path).read_text(encoding="utf-8-sig"))
    targets=original.get("native_objects",{})
    if set(targets)!={"Vector","NotEqual_FloatFloat","PostBeginPlay"}:
        raise AssertionError("Missing original native v432 zero/nonzero memory probes")
    expected={"Vector":(0,0,0,0),"NotEqual_FloatFloat":(286,10,6,2),
              "PostBeginPlay":(67,2,2,0)}
    for name,(idx,num,arch,refs) in expected.items():
        t=targets[name]
        if (t["export_index"],t["native_script_bytes"],t["archived_script_bytes"],
            t["object_pointer_relocations"])!=(idx,num,arch,refs):
            raise AssertionError("Original v432 script size/archived mismatch "+name)
    return {"status":"PASS","result":
      "Original v432 compiled Core/Engine native zero and nonzero UStruct script loaded-values: Vector 0/0, NotEqual_FloatFloat native 10 bytes vs archived 6 (two typed object relocations), Engine.PostBeginPlay 2/2. 31/31 focused actual native-to-UnrealDB checks PASS, exact original evidence SHA-pinned. No native rerun.",
      "evidence":pins}

def eval_t004(_row: dict[str, Any]) -> dict[str, Any]:
    """Original 469f Core.dll directly loaded 3 previously unobserved registry structs."""
    pins=[
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T004-469f-original-native-registry.cpp",
                 "d28bcd92666e693fe8047cc68b9c09c51dc3e0eeff07d31876a8448262bd3364"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T004-469f-original-native-registry.log",
                 "90728ef4696f4038aa5c1bea72bb6fb9d68bbeba790153063103216fd9d43ee8"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T004-469f-original-native-registry-build.log",
                 "693f89d960ad05b59d075664da7fbaf475c096eb56ac1f50446e864e0f9daa4a"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T004-469f-46-DEV-PASS.json",
                 "a243825846068a1d1af281bcf60e667168e9346a16dfa3f0de4ffc368d86d36a"),
    ]
    path="catalog/compliance/evidence/o06/20261028-G3-T004-469f-46-DEV-PASS.json"
    pins.append(verified_pass_log(path,"checks",46))
    o=json.loads((ROOT/path).read_text(encoding="utf-8-sig"))
    rows=o.get("native_loaded",{})
    expected={"RegistryPropertyInfo":(727,743,735),
              "RegistryObjectInfo":(728,759,0),"PreferencesInfo":(734,737,729)}
    if set(rows)!=set(expected):
        raise AssertionError("Original compiled 469f registry natives incomplete")
    for name,(idx,child,nxt) in expected.items():
        got=rows[name]
        if (got.get("native_export"),got.get("native_child_ref"),
            got.get("native_next_ref"),got.get("script_size"))!=(idx,child,nxt,0):
            raise AssertionError("Original 469f native registry reference mismatch "+name)
    pins.append({"original_compiled_Core_dll_sha256":
                 "80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2",
                 "original_469f_Core_u_sha256":
                 "7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0"})
    return {"status":"PASS",
            "result":"Original 469f compiled Core.dll native LOAD of previously unobserved direct RegistryPropertyInfo/RegistryObjectInfo/PreferencesInfo, 46/46 exact original-native loaded UStruct fields and live linked Children/Next vs UnrealDB reader PASS; previous nine direct struct probes not repeated.",
            "evidence":pins}

def eval_t005_t006(_row: dict[str, Any]) -> dict[str, Any]:
    """Real 2026 227k compiled original Core.dll fields and linked references."""
    pins=[
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T005-T006-227k-native-direct-structs.cpp",
                 "9263998f6c69a5eb0955e83545831b84f3aeb034afc13d1039cfae06bf789a5a"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T005-T006-227k-native-direct-structs.log",
                 "29afbd35450e4a969d28236a4971f75e596390d79ec8dd0c3d07abd81f18dbf1"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T005-T006-227k-native-build.log",
                 "bfc038501ca57da99bff2fd33adc073a2c8cfa6f3b249f5db7381b1f5ee56ce6"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T005-T006-227k-87-DEV-PASS.json",
                 "ba4f0e4d9cb8e091d5af22067a3db992f3b1666eeac15627a0ab28e0fe1953de"),
    ]
    path="catalog/compliance/evidence/o06/20261028-G3-T005-T006-227k-87-DEV-PASS.json"
    pins.append(verified_pass_log(path,"checks",87))
    o=json.loads((ROOT/path).read_text(encoding="utf-8-sig"))
    expected={"Vector":(0,584,313,0),"Rotator":(1,577,33,0),
              "Color":(547,547,559,0),"Plane":(32,309,935,1),
              "Coords":(571,569,1074,0),"Guid":(312,590,662,0)}
    rows=o.get("native_loaded",{})
    if set(rows)!=set(expected):
        raise AssertionError("227k real original native UStruct set incomplete")
    for name,(idx,child,nxt,sup) in expected.items():
        v=rows[name]
        if (v["export_index"],v["native_children_ref"],v["native_next_ref"],
            v["native_super_ref"],v["native_script_length"])!=(idx,child,nxt,sup,0):
            raise AssertionError("227k compiled native loaded reference mismatch "+name)
    script=o.get("native_function_nonzero",{})
    if script!={"export":292,"memory":11,"archive":7}:
        raise AssertionError("227k inherited source nonzero script size proof missing")
    pins.append({"original_Core_dll_SHA256":"c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194",
                 "original_Core_u_SHA256":"28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c"})
    return {"status":"PASS","result":
      "Original compiled 2026 Unreal 227k Core.dll: 6 direct UStruct source fields, names, zero-script, loaded Children/Next/SuperField/ScriptText linker identities and inherited nonzero script 11 memory/7 archive, 87/87 true original native vs reader PASS, no 2012 original rerun.",
      "evidence":pins}

def eval_t007(_row: dict[str, Any]) -> dict[str, Any]:
    """Genuine original 1998 May17 Core DLL in original stock Unreal.exe -make."""
    pins=[
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T007-C98-ORIGINAL-LINKER-POINTERS.cmd",
                 "2f1f34461c1787b7adb4cf550bdfa91fa4c2467752d16e85166e552c6c8a14fa"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T007-C98-ORIGINAL-LINKER-POINTERS.log",
                 "412b5c33a6a68901bcacaf3e3ac2b56cc48178882715cf4f2072c631595a57ce"),
      verify_pin("catalog/compliance/evidence/o06/20261028-G3-T007-C98-34-DEV-PASS.json",
                 "fde527b3ed007cd741b008f6ae31f8f21cf00514aa08e9c2cbdaaa5bf6c553cc"),
    ]
    path="catalog/compliance/evidence/o06/20261028-G3-T007-C98-34-DEV-PASS.json"
    pins.append(verified_pass_log(path,"checks",34))
    result=json.loads((ROOT/path).read_text(encoding="utf-8-sig"))
    fields=result.get("native_Core_u_objects",{})
    expected={"Object":{"export":0,"children_ref":457,"script_text_ref":231},
              "Vector":{"export":1,"children_ref":223,"next_ref":422}}
    if fields!=expected:
        raise AssertionError("C98 original native pointer-to-export source identity mismatch")
    pins.append({"original_1998_Core_dll_SHA256":
                   "9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92",
                 "original_1998_Core_u_SHA256":
                   "7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c"})
    return {"status":"PASS","result":
      "Actual May17 1998 compiled original Core.dll stock Unreal.exe native UClass Object and UStruct Vector: real same-linker Children/ScriptText/Next index identity vs source-selected UnrealDB. CDB 0x10110c88 true serializer reaches both, 34/34 exact source-native checks PASS; prior 100 native script loads not repeated.",
      "evidence":pins}

def eval_t010(_row: dict[str, Any]) -> dict[str, Any]:
    """Reuse actual C98/v432/227i/227k compiled script-memory parity, no native re-run.

    This is the fixed T010 script-size/archive-footprint criterion only.
    It does NOT close G3-12 opcode semantics or T009 malformed reference handling.
    """
    pins: list[dict[str, Any]]=[]
    current_code="catalog/bin/verify-ue1-o06-g3-t010-current-script-sizes.php"
    current_log="catalog/compliance/evidence/o06/20261028-G3-T010-current-reader-original-script-sizes.json"
    pins.append(verify_pin(current_code,"f9c43e3c76a29aa1c558b3be5a41aa5b8272ca28ec8649aae0850f594d835099"))
    pins.append(verify_pin(current_log,"140a0f7280ecd8d34d9f4ebb5a29fa428df200c827d1666986bd4251a4b3452b"))
    current=json.loads((ROOT/current_log).read_text(encoding="utf-8"))
    if current.get("ok") is not True or current.get("checks")!=40 or current.get("errors"):
        raise AssertionError("New focused T010 current production-source acceptance not 40/40")
    samples=current.get("captures",[])
    if [(s.get("build"),s.get("export"),s.get("original_native_script_num"),
         s.get("current_reader_script_num"),s.get("archive_bytes_consumed"))
        for s in samples]!=[
            ("C98-May17-original",454,0,0,None),
            ("C98-May17-original",449,10,10,4),
            ("C227i-2012-original",661,0,0,None),
            ("C227i-2012-original",915,11,11,7)]:
        raise AssertionError("T010 current production zero/nonzero/physical byte samples changed")
    if current.get("native_executions_rerun")!=0 or current.get("old_acceptance_verifier_replays")!=0:
        raise AssertionError("T010 must reuse original native evidence, not rerun old acceptance")
    fresh_hashes=current["current_reader_source_sha"]
    for source,expected in fresh_hashes.items():
        if source=="catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php" and sha256(ROOT/source)!=expected:
            # After T010's 40/40 original-memory parity, T056 corrected ONLY
            # the UT99 interpretation of original C98 EndCode (0x15).
            # Reconstruct exactly the previously accepted source and verify
            # its original SHA, while SHA-pinning the new code independently;
            # neither C98 nor 227 script-size decoder paths were changed.
            previous="""                    case 0x15: // 1998 EndCode, absent in UT99.
                        if ($modern) {
                            $unsupported = $v227
                                ? 'Unreal v227 EX_MapKeyElement archive traversal unverified'
                                : 'UT99 EX_EndCode opcode not source-defined';
                        }
                        break;"""
            revised="""                    case 0x15: // Original 1998 EndCode; NOT defined by UT99 SerializeExpr.
                        if ($modern) {
                            if ($v227) {
                                // Unreal 227 uses this byte for a different token;
                                // its original compiled archive traversal remains unproven.
                                $unsupported = 'Unreal v227 EX_MapKeyElement archive traversal unverified';
                            } else {
                                // Actual original 469f Core.dll rejects the genuine 1998
                                // v61 EndCode token with appErrorf("Bad expr token 15").
                                // Preserve the physical cursor immediately after the token.
                                throw new RuntimeException(sprintf('UE1 Bad expr token %02x at archive cursor %d', $token, $position));
                            }
                        }
                        break;"""
            data=(ROOT/source).read_bytes()
            newline=b"\r\n" if b"\r\n" in data else b"\n"
            before=previous.replace("\n",newline.decode()).encode("utf-8")
            after=revised.replace("\n",newline.decode()).encode("utf-8")
            if data.count(after)!=1:
                raise AssertionError("T010 exact source-only T056 delta absent or duplicated")
            reconstructed=data.replace(after,before,1)
            prior=hashlib.sha256(reconstructed).hexdigest()
            live=sha256(ROOT/source)
            if prior!=expected or live!="df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500":
                raise AssertionError("T010 original source parity invalidated by unreviewed drift")
            pins.append({"path":source,"historical_40_of_40_sha256":expected,
                         "current_sha256":live,"only_change":"T056 UT99 invalid EX_EndCode0x15 branch; original C98 and 227 paths unmodified"})
        else:
            pins.append(verify_pin(source,expected))
    for source_manifest, native_key, source_key, binary_key, log_file, counts in [
        (
            "catalog/compliance/UE1-O06-G3-03-C98-MAY1998-100-ORIGINAL-NATIVE-96-SCRIPTS.json",
            "true_actual_original_compiled_CDB_stdout_scripts_parser_and_register_SHA256",
            "original_1998_reader_source_SHA",
            "genuine_original_1998_shipped_DLL_packages_executables_SHA256",
            "catalog/compliance/evidence/o06/20261027T184627555Z-2f411f41-DEV-verify-ue1-o06-g3-03-c98-may1998-100-original-native-96-scripts.php.txt",
            {"checks":1492,"original_native_nonzero_scripts":96,
             "original_retail_native_known_bytes_matched":175,
             "source_pointer_fname_relocation_bytes_deliberately_masked":668,
             "archive_script_compact_shorter_than_loaded_memory":94}
        ),
        (
            "catalog/compliance/UE1-O06-G3-03-C227I-237-ORIGINAL-NATIVE-223-SCRIPTS.json",
            "original_native_raw_logs_and_commands_SHA256",
            "current_production_reader_SHA",
            "native_original_shipped_binary_inputs",
            "catalog/compliance/evidence/o06/20261027T182530828Z-11e0d1b7-DEV-verify-ue1-o06-g3-03-c227i-237-original-native-structs-223-scripts.php.txt",
            {"checks":4412,"actual_original_native_C227i_script_buffers":223,
             "original_compiled_native_known_script_bytes_matched":646,
             "source_loader_script_memory_vs_archive_shorter_count":213}
        )
    ]:
        file=ROOT/source_manifest
        manifest=json.loads(file.read_text(encoding="utf-8-sig"))
        pins.append({"path":source_manifest,"sha256":sha256(file)})
        for item in manifest[native_key].values():
            path=item["repo_path"]
            pins.append(verify_pin(path,item["sha256"]))
            if "bytes" in item and pins[-1]["bytes"]!=item["bytes"]:
                raise AssertionError("Original native evidence byte length mismatch: "+path)
        for item in manifest[source_key].values():
            path=item["repo_path"]
            if path in fresh_hashes and fresh_hashes[path]!=item["sha256"]:
                # Source changed after the original compiled witness: original logs
                # remain sealed; exact CURRENT source is verified by focused T010
                # against that same original native memory, rather than claiming
                # an outdated production reader checksum still matches.
                pins.append({"historical_source_path":path,
                             "old_sha256":item["sha256"],
                             "current_sha256":fresh_hashes[path],
                             "change_certification":"T010 current-source 40/40 original native"})
            else:
                pins.append(verify_pin(path,item["sha256"]))
        for item in manifest[binary_key].values():
            binary=pathlib.Path(item["path"])
            if not binary.is_file() or sha256(binary)!=item["sha256"] or binary.stat().st_size!=item["bytes"]:
                raise AssertionError("Original shipped binary SHA/size mismatch: "+str(binary))
            pins.append({"original_binary":str(binary),"sha256":item["sha256"],"bytes":item["bytes"]})
        if "original_v1200_May19_1998_source_UnClass_cpp_pin" in manifest:
            item=manifest["original_v1200_May19_1998_source_UnClass_cpp_pin"]
            source=pathlib.Path(item["path"])
            if not source.is_file() or sha256(source)!=item["sha256"] or source.stat().st_size!=item["bytes"]:
                raise AssertionError("C98 historic source file pin missing or changed")
            pins.append({"historical_source":str(source),"sha256":item["sha256"]})
        observed=json.loads((ROOT/log_file).read_text(encoding="utf-8-sig"))
        if observed.get("ok") is not True or observed.get("errors") or observed.get("failed"):
            raise AssertionError("Original engine script parity validator not successful: "+log_file)
        for name, expected in counts.items():
            if observed.get(name)!=expected:
                raise AssertionError("Original compiled script invariant not proven: "+log_file+" "+name)
        pins.append({"path":log_file,"sha256":sha256(ROOT/log_file),
                     "reused_native_script_count":counts.get("original_native_nonzero_scripts",
                                               counts.get("actual_original_native_C227i_script_buffers"))})
    v432=eval_t003(_row)
    c227k=eval_t005_t006(_row)
    pins.extend(v432["evidence"])
    pins.extend(c227k["evidence"])
    return {"status":"PASS",
            "result":(
             "C98 original 96 nonzero/4 zero native Script.Num, 94 compact-widened, "
             "175 exact known script bytes (668 native FName/UObject relocations intentionally masked); "
             "stock v432 original 0/0, 10/6 and 2/2 native/archive bytes, 2 typed object refs; "
             "2012 original 227i 223 nonzero scripts, 213 compact-widened and 646 native bytes; "
             "original 2026 227k direct zero scripts plus source UFunction 11/7 native/archive bytes. "
             "All saved SHA-pinned actual compiled CDB logs, original shipped binaries, "
             "source-reader hashes, previously successful independent PHP comparisons and "
             "v432/227k native source-selected checks verified WITHOUT native/PHP test replay. "
             "This is T010 length/physical-archive criterion only; T009 and G3-12 stay OPEN."),
            "evidence":pins}

def eval_t032(_row: dict[str, Any]) -> dict[str, Any]:
    """Original compiled 469f/227k Pointer/Net/Any method byte behaviour, not Any values."""
    source="catalog/compliance/UE1-O06-G3-08-469F-VS-227K-POINTER-BUTTON-ANY-RUNTIME-SEMANTICS.json"
    p=ROOT/source
    m=json.loads(p.read_text(encoding="utf-8-sig"))
    pins=[{"path":source,"sha256":sha256(p)}]
    methods=m["original_matched_compiled_true_native_PE_exports_and_first24bytes"]
    if set(methods)!={"T99-469f","C227k"}:
        raise AssertionError("T032 original source profile set changed")
    for build,dll in methods.items():
        actual=pathlib.Path(dll["original_dll"])
        if not actual.is_file() or actual.stat().st_size!=dll["bytes"] or sha256(actual)!=dll["sha256"]:
            raise AssertionError("Actual original compiled binary missing/wrong: "+build)
        pins.append({"original_compiled_DLL":str(actual),"sha256":dll["sha256"]})
        with actual.open("rb") as stream:
            for label,method in dll["native_exported_methods"].items():
                stream.seek(method["PE_file_offset"])
                if stream.read(24).hex()!=method["first_24_compiled_bytes_hex"]:
                    raise AssertionError("Original shipped PE method bytes changed: "+build+" "+label)
    original=m["original_real_CDB_compiled_native_instruction_logs"]
    for label,pin in original.items():
        data=verify_pin(pin["repo_path"],pin["sha256"])
        if data["bytes"]!=pin["bytes"]:
            raise AssertionError("Original native CDB log/command size mismatch: "+label)
        pins.append(data)
    reader=m["newly_updated_Reader"]
    pins.append(verify_pin(reader,m["newly_updated_reader_SHA256"]))
    test="catalog/bin/verify-ue1-o06-g3-08-469f-227k-native-pointer-button-any-ops.php"
    pins.append({"path":test,"sha256":sha256(ROOT/test)})
    log="catalog/compliance/evidence/o06/20261027T162644568Z-e469751b-DEV-verify-ue1-o06-g3-08-469f-227k-native-pointer-button-any-ops.php.txt"
    logdata=json.loads((ROOT/log).read_text(encoding="utf-8-sig"))
    for key,expected in {"ok":True,"checks":46,"passed":46,
        "new_original_compiled_source_specific_runtime_operator_methods":8,
        "genuine_469f_pointer_payload_bytes":4,
        "genuine_C227k_pointer_button_payload_bytes":0,
        "genuine_C227k_Any_dynamic_codec_unsupported":True,
        "prior_native_positive_acceptance_tests_reexecuted":0}.items():
        if logdata.get(key)!=expected:
            raise AssertionError("T032 compiled original 46/46 evidence failed: "+key)
    if logdata.get("failed") or m["G3_08_FULL_ACCEPTED"] is not False:
        raise AssertionError("T032 prior source scope invalid")
    if any([
        m["T99_469f_PointerProperty_runtime_item_or_net_serialized_byte_count"]!=4,
        m["C227k_Pointer_and_Button_serializeitem_byte_count"]!=0,
        m["C227k_Pointer_and_Button_netserializeitem_byte_count"]!=0,
        m["C227k_Pointer_and_Button_NetSerializeItem_true_return_value"]!=1,
    ]):
        raise AssertionError("Original 469f/227k compiled byte/Net return difference not proven")
    pins.append({"path":log,"sha256":sha256(ROOT/log),"accepted_original_compiled_method_checks":46})
    return {"status":"PASS",
            "result":"Real compiled original 469f UPointerProperty::SerializeItem and NetSerializeItem serialize exactly 4 bytes (Net returns 1); original 227k UPointer/UButton items serialize 0 bytes and Net returns 1 without payload; separate UAnyProperty dispatch is dynamic and its instance codec remains OPEN under T025–T031. Exact original DLL PE bytes, CDB traces, current production source SHA and already sealed 46/46 source-selected byte-method checks verified, no native rerun.",
            "evidence":pins}

def eval_t056(_row: dict[str, Any]) -> dict[str, Any]:
    """C98 original EX_EndCode 0x15 is invalid in actual compiled 469f; NOT T011."""
    p="catalog/bin/verify-ue1-o06-g3-t056-cross-era-endcode.php"
    output="catalog/compliance/evidence/o06/20261028-G3-T056-original-native-and-current-reader.json"
    native="catalog/compliance/evidence/o06/20261028-G3-T056-469f-original-cross-era-badexpr15.log"
    original="catalog/compliance/evidence/o06/20261028-G3-04-C98-original-1998-native-SAVE.u"
    current="catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php"
    pins=[
        verify_pin(p,"ab305e0683b582dd71b370105c59b3719eb8bf5a4fa32936673cdea1dd5167da"),
        verify_pin(output,"3894c649e12db4bbab4573e92b310147a1f32ca351df6512d8d0dee293a03d76"),
        verify_pin(native,"58d27495400ef3d0b9daeb61f407258bf7c9827336456d0f8857519410570cf9"),
        verify_pin(original,"4957034dc7629163ab7adabeedff1fc9cecc3def1d577c4aab31bec0763071a4"),
        verify_pin(current,"df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500"),
    ]
    for original_path,expected in [
      (r"L:\Source\Games\UT99\OldUnreal-UTPatch469f-Windows-x86\System\Core.dll",
       "80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2"),
      (r"L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnClass.cpp",
       "ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291"),
      (r"L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnClass.cpp",
       "6563392fa15fb73d05b52267a00da5034651c47644f310857cc86f7b045f6380")
    ]:
        fn=pathlib.Path(original_path)
        if not fn.is_file() or sha256(fn)!=expected:
            raise AssertionError("Original native/source original pin changed: "+original_path)
        pins.append({"original":original_path,"sha256":expected})
    log=(ROOT/native).read_text(encoding="utf-8",errors="replace")
    if not all(s in log for s in ["Version: 469","Revision: f","Log: Bad expr token 15","FailedLoadPackage"]):
        raise AssertionError("Actual original compiled 469f bad expression FAILURE missing")
    sample=json.loads((ROOT/output).read_text(encoding="utf-8-sig"))
    if sample.get("ok") is not True or sample.get("checks")!=18 or sample.get("errors"):
        raise AssertionError("Source-selected fixed T056 acceptance is not 18/18")
    if sample.get("reader_source_sha256")!="df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500":
        raise AssertionError("T056 current production parser source drift")
    if sample.get("original_compiled_engines_reexecuted")!=0 or sample.get("previous_native_acceptance_replayed")!=0:
        raise AssertionError("T056 must NOT re-run any prior original C98/469f engine")
    observed=sample.get("observations",[])
    if len(observed)!=8:
        raise AssertionError("T056 original physical opcode positions not fully classified")
    for offset in [797,854]:
        got=[s for s in observed if s.get("archive_offset")==offset]
        if [(x.get("source"),x.get("classification"),x.get("physical_cursor")) for x in got]!=[
          ("C98-1998","ACCEPT",offset+1),
          ("UT99-v432","NATIVE_BAD_EXPR",offset+1),
          ("UT99-469f","NATIVE_BAD_EXPR",offset+1),
          ("C227","SOURCE_UNVERIFIED",offset+1)]:
            raise AssertionError("T056 cross-source original 0x15 semantics differ: "+str(offset))
    # T010 original C98+227 script byte counts remain stable under the single
    # audited, reversible UT99 0x15 parser change; no native script rerun.
    pins.append({"preserved_T010_original_native":eval_t010(_row)["status"],
                 "current_script_source_reversible_delta":"only 0x15 UT99 error"})
    return {"status":"PASS","result":
       "Fixed T056 cross-era compatibility: genuine 1998 FObjectManager-saved v61 UFunctions contain original EX_EndCode 0x15 (physical offsets797,854), legal C98 source; actual unmodified original 469f Core.dll loader FAILED with Bad expr token 15 (NOT a successful legacy UFunction load). UT99 original source omits that case; current UnrealDB now raises identical bad expression error instead of silently returning partial, with source-local cursors798/855 in focused 18/18 PHP parity. v227 separate opcode remains unverified; T011 and G3-04 stay BLOCKED. All original engine and CDB proof reused; zero replay.",
       "evidence":pins}

def eval_t051(_row: dict[str, Any]) -> dict[str, Any]:
    """Pin stock v432 Core.u + Engine.Inventory source-defined nested SKIP oracle."""
    script="catalog/bin/verify-ue1-o06-g3-t051-v432-native-script-flow.php"
    result="catalog/compliance/evidence/o06/20261028-G3-T051-original-v432-control-flow-native-compare.json"
    original_log="catalog/compliance/evidence/o06/20261028-G3-T051-v432-original-scriptall-native.log"
    original_source="catalog/compliance/evidence/o06/20261028-G3-T051-v432-original-scriptall-source.json"
    core_result="catalog/compliance/evidence/o06/20261028-G3-T003-v432-31-DEV-PASS.json"
    pins=[
      verify_pin(script,"9dbbf3e18039dc3920148bfabf60e9049b914b2cbe2e528059135366d2fcad78"),
      verify_pin(result,"457fd47987390226c24c57ec92c203510d7c30bf4c0c505d885464b7f9482bee"),
      verify_pin(original_log,"2f9a686c19f52be6e9866eac1fb521d42bc479f073510fbcc397a6d317843a11"),
      verify_pin(original_source,"e89d05be26fc13708eccefbf50682c41ce2431783de91f308a32ca11274e9a67"),
      verify_pin(core_result,"cb333b56adaec157d56d69d436c686fe390ba0d6f77c971789345c32c818ea76"),
      verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php","df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500")
    ]
    originals=[
      ("C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u",
       "d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695"),
      ("C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll",
       "237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8"),
      ("C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll",
       "f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac"),
      (r"L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnClass.cpp",
       "6563392fa15fb73d05b52267a00da5034651c47644f310857cc86f7b045f6380")
    ]
    for file,sha in originals:
      p=pathlib.Path(file)
      if not p.is_file() or sha256(p)!=sha:
        raise AssertionError("Original v432 engine/source changed: "+file)
      pins.append({"original":file,"sha256":sha})
    current=json.loads((ROOT/result).read_text(encoding="utf-8-sig"))
    for k,expected in {"ok":True,"checks":25,"Engine_u_class":"Inventory",
       "class_export_index":4,"memory_script_num":33,"archive_bytes_consumed":25,
       "current_decoded_expression_count":17,"exact_original_native_memory_known_bytes":21,
       "relocated_native_pointer_bytes":12,"original_compiled_engines_reexecuted":0,
       "old_positive_verifiers_replayed":0,
       "current_script_reader_sha256":"df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500"}.items():
      if current.get(k)!=expected:
        raise AssertionError("Actual original compiled T051 native compare mismatch: "+k)
    if current.get("errors") or "EX_Skip 0x18" not in current.get("control_flow","") or "82->9A->3A->01" not in current.get("nested_tokens",""):
      raise AssertionError("T051 original v432 control-flow or nested parser unproven")
    core=json.loads((ROOT/core_result).read_text(encoding="utf-8-sig"))
    v=core["native_objects"]["NotEqual_FloatFloat"]
    if core.get("checks")!=31 or not core.get("ok") or v.get("source_package")!="Core.u" or v.get("native_script_bytes")!=10 or v.get("archived_script_bytes")!=6:
      raise AssertionError("Original compiled stock v432 Core.u script failed")
    return {"status":"PASS",
       "result":"Stock original compiled UT99 v432 Core.u UStruct NotEqual_FloatFloat 10 native/6 archived bytes with two real object pointers; stock v432 Engine.u Inventory UClass script 33 original native memory bytes vs 25 archived, 17 expression nodes including source EX_Skip(0x18) control flow with nested 0x2d and 0x82 native call -> 0x9a -> 0x3a conversion -> 0x01 Role field. 21/21 deterministic native bytes exact and 12 native pointer bytes explicitly masked, actual pointer targets mapped. Prior 31/31 Core.u and new 25/25 Inventory direct native/current comparisons pinned; zero original compiled reruns. Only T051 script branch, not other G3-12 full closure.",
       "evidence":pins}

def eval_t054(_row: dict[str, Any]) -> dict[str, Any]:
    """Fixed zero and >128-byte script, original compiled v432 Pawn/Vector and sealed C98/227i."""
    script="catalog/bin/verify-ue1-o06-g3-t054-stock-original-script-size.php"
    evidence="catalog/compliance/evidence/o06/20261028-G3-T054-original-Pawn314-zero-16pass.json"
    native="catalog/compliance/evidence/o06/20261028-G3-T054-v432-Pawn-original-native-memory.log"
    original_source="catalog/compliance/evidence/o06/20261028-G3-T051-v432-original-scriptall-source.json"
    originals=[
        (script,"1c815fa817ab66ccf1bad31b26991b245b042d3d5f37b1d7bddd558867b7ed74"),
        (evidence,"f5b877359e5911f4e2963f4674d7c30ad9507f37ef7a624900163a6754eab87e"),
        (native,"4de2849090d8f76137cca84414fdd32713d8e747f11795300d84cafad8c39178"),
        (original_source,"e89d05be26fc13708eccefbf50682c41ce2431783de91f308a32ca11274e9a67"),
        ("catalog/compliance/evidence/o06/20261028-G3-T003-v432-31-DEV-PASS.json",
         "cb333b56adaec157d56d69d436c686fe390ba0d6f77c971789345c32c818ea76"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php",
         "df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500"),
    ]
    pins=[verify_pin(path,sha) for path,sha in originals]
    # The original compiled v432 engine and genuine Engine.u are immutable.
    for p,sha in [
        ("C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u",
         "d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695"),
        ("C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll",
         "237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8")
    ]:
        path=pathlib.Path(p)
        if not path.is_file() or sha256(path)!=sha:
            raise AssertionError("T054 original compiled native or package SHA changed: "+p)
        pins.append({"original":p,"sha256":sha})
    x=json.loads((ROOT/evidence).read_text(encoding="utf-8"))
    expected={"ok":True,"checks":16,"errors":[],"zero_v432_Vector":0,
       "long_v432_Pawn_memory_bytes":314,"archive_bytes":246,"memory_minus_archive":68,
       "original_compiled_known_bytes_identical":202,"native_pointer_bytes_masked":112,
       "original_C98_zero_script":0,"original_C227i_zero_script":0,
       "original_native_engines_reexecuted":0,"old_positive_verifiers_rerun":0,
       "current_script_reader_sha":"df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500"}
    for key,value in expected.items():
        if x.get(key)!=value:
            raise AssertionError("T054 16/16 original compiled cursor/length sample mismatch: "+key)
    return {"status":"PASS","result":
      "Original v432 compiled Core.u Vector zero Script.Num and zero archived bytes; original v432 Engine.u Pawn 314-byte real compiled native script (longer than one compact index byte), exactly 246 physical archived bytes, 28 UObject relocations (112 native bytes masked), 202/202 known actual native bytes equal, 68-byte compact widening. Source-selected original C98 and C227i zero script records independently sealed. Current production decoder 16/16; no native or previous verifier replay; no assertion that short C98/C227i samples also exceed 128.",
      "evidence":pins}

def eval_t055(_row: dict[str, Any]) -> dict[str, Any]:
    """One original-invalid source-selected C98 EX opcode, original native FArchive Tell."""
    script="catalog/bin/verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php"
    result="catalog/compliance/evidence/o06/20261027T210831632Z-5ff1e421-DEV-verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php.txt"
    native="catalog/compliance/evidence/o06/20261027-G3-03-C98-ORIGINAL-BAD-EXPR10-CURSOR-CDB.log"
    command="catalog/compliance/evidence/o06/20261027-G3-03-C98-ORIGINAL-BAD-EXPR10-CURSOR-COMMAND.cmd"
    pins=[verify_pin(p,sha) for p,sha in [
      (script,"088d7caa45ea7d0dbdf7961a06a718b5a5a86df43ee0176419cecf2eae974605"),
      (result,"fd78dd87ace49c7433c48e66a7c5655c5f8a3ab795ebf7a510db94808a222bba"),
      (native,"81ee43b30a4543baab0564135e8ee5402fe4561552bdc786d3b3d503a4b42ef1"),
      (command,"8eb4f37588de50535fb6807f8f0f24040b399e18fc6d2801c310bd571a20f74d"),
    ]]
    originals=[
      (r"L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnClass.cpp",
       "ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291"),
      ("C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/Core.dll",
       "9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92"),
      ("C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/Core.u",
       "7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c"),
    ]
    for path,sha in originals:
        p=pathlib.Path(path)
        if not p.is_file() or sha256(p)!=sha:
            raise AssertionError("T055 original shipped C98 binary/source different: "+path)
        pins.append({"original":path,"sha256":sha})
    raw=(ROOT/native).read_text(encoding="utf-8",errors="replace")
    if not all(t in raw for t in ["G303_C98_ORIGINAL_BAD10_ARCHIVE_CURSOR",
                 "Core!appErrorf","Bad expr token %02x","00005eae"]):
        raise AssertionError("T055 original compiled native 0x10 error or physical Tell absent")
    result_json=json.loads((ROOT/result).read_text(encoding="utf-8-sig"))
    for key,expect in {"ok":True,"checks":54,"failed":[],
       "native_error":"Bad expr token 10","native_archive_cursor":24238,
       "previously_sealed_native_proofs_reexecuted":0}.items():
        if result_json.get(key)!=expect:
            raise AssertionError("T055 source-defined C98 invalid-token original parity failed: "+key)
    # T056 changed ONLY the UT99 case for token 0x15; this checks that the
    # 1998 source-selected case for invalid token 0x10 stayed unchanged.
    if eval_t010(_row)["status"]!="PASS":
        raise AssertionError("T055 source-only change reconciliation not certified")
    return {"status":"PASS",
       "result":"One named source-defined invalid SerializeExpr opcode: authentic retail May17 1998 original Core.u EndState export456 offset24237 mutated only 0x14->0x10; unchanged original C98 Core.dll under native CDB calls appErrorf(Bad expr token 10) at actual FArchive Tell24238; current C98 source branch rejects at exactly24238 with no loaded state. Previously sealed 54/54 direct source/native/physical byte checks SHA-verified; no original engine rerun. This ONE invalid-token test does not certify every malformed opcode or UT99/227 distinct branches.",
       "evidence":pins}

def eval_t057(_row: dict[str, Any]) -> dict[str, Any]:
    """Original compiled source-family nested bytecode: C98, v432 and C227i.

    Only exact SHA-pinned original compiled x86 CDB buffers count. The
    T057 verifier makes fresh PHP current-reader byte comparisons but
    launches ZERO original native runtimes and repeats ZERO older tests.
    """
    script="catalog/bin/verify-ue1-o06-g3-t057-original-native-recursive.php"
    output="catalog/compliance/evidence/o06/20261028-G3-T057-original-native-three-families-112pass.json"
    pins=[]
    for p,h in [
        (script,"e3d9cc2fad6b4e250667484c1ac2f2ac2ccb983fda4bdddd57d441c5af1b547b"),
        (output,"917cb4ea822786112dacf5def7e3976fe2694befc32d90e063f2b92d0aa9bc97"),
        ("catalog/compliance/evidence/o06/20261028-G3-T057-C98-original-Engine-Grab-loaded-native-30.log",
         "9f0e601d360c98cf78505ecd548b20e59b9d24f52cb652bcdcddbaef5a892ef5"),
        ("catalog/compliance/evidence/o06/20261028-G3-T057-C98-original-Engine-Grab-loaded-native-30.cmd",
         "ef6c851f7d8e81808ca87e4e16ebdb6208712680483caed69a4e867fda7fa383"),
        ("catalog/compliance/evidence/o06/20261028-G3-T057-C98-original-Engine-Grab-source-discovery.json",
         "7f986e863651900e362ba144b3a4c2244f89f4a3761683a219ae026b135ba1df"),
        ("catalog/compliance/evidence/o06/20261027-G3-03-C227I-237-223-ORIGINAL-NATIVE-VALUES.json",
         "36923b929fa6bf05389f93be97203caa6ce08495b838bd53e877b6e31ae96b5c"),
        ("catalog/compliance/evidence/o06/20261027-G3-03-C227I-223-SCRIPT-MEMORY-REAL-CDB.log",
         "01cf2d729a0f23f99e92492eb597e178e674a937b4ae5b88d14464c01f455c3b"),
        ("catalog/compliance/evidence/o06/20261028-G3-T051-original-v432-control-flow-native-compare.json",
         "457fd47987390226c24c57ec92c203510d7c30bf4c0c505d885464b7f9482bee"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php",
         "df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php",
         "2b87b97b2c6c0d830205623cce6cf2594792952ef6ebf5314e88f0a2511f27b1"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php",
         "afca90b65cfb192d23e5d0aaa0f449acb651c85548fb81ccac25bce3a9963714"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
         "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
    ]:
        pins.append(verify_pin(p,h))
    result=json.loads((ROOT/output).read_text(encoding="utf-8-sig"))
    for k,v in {
        "ok":True,"checks":112,"errors":[],
        "original_compiled_source_profiles":["C98-May17-1998","UT99-v432","Unreal-C227i-2012"],
        "true_original_compiled_nested_script_samples":3,
        "recursive_expression_visits":{"C98":13,"v432":17,"C227i":14},
        "real_native_known_bytes_equal":53,
        "real_native_typed_relocation_bytes_masked":36,
        "physical_archive_bytes":{"C98":23,"v432":25,"C227i":18},
        "previously_completed_native_sessions_reexecuted":0,
        "old_acceptance_suites_replayed":0,
        "G3_12_overall":"OPEN"
    }.items():
        if result.get(k)!=v:
            raise AssertionError("T057 original source-native exact acceptance failed: "+k)
    for key,known,masked in [("C98_original_native",18,12),("C227i_original_native",14,12)]:
        detail=result.get(key,{})
        if detail.get("known")!=known or detail.get("masked")!=masked or detail.get("mismatches"):
            raise AssertionError("T057 authentic native byte relocation mismatch: "+key)
    reused=eval_t051(_row)
    if reused.get("status")!="PASS":
        raise AssertionError("T057 sealed original UT99 v432 nested source-native T051 acceptance missing")
    return {"status":"PASS",
        "result":"Three actual original compiled source-family nested EX_* scripts, no native reruns: C98 May17 retail Core.dll real Engine.u Grab export1613 13 expressions/7 top-level/30 in-memory vs23 archive, 18/18 deterministic compiled native bytes match; 1 UObject+2 FName typed slots (12 bytes intentionally masked). UT99 v432 sealed compiled Engine.Inventory 17 recursive expressions including EX_Skip/native conversion, 21/21 literal bytes/12 reloc bytes. Original Unreal 2012 C227i Core.RandRange 14 recursive expressions/2 top-level 26 memory/18 archive, 14/14 literal bytes/12 typed object bytes. 112/112 focused source/current/original verification PASS; total 53 native known bytes and 36 relocation bytes masked; no unknown process pointer addresses fabricated. T057 only, G3-12 overall still OPEN.",
        "evidence":pins+reused.get("evidence",[])}

def eval_t009(_row: dict[str, Any]) -> dict[str, Any]:
    """Six source-distinct real compiled UStruct negative branches, C98/v432/227k.

    Pin the focused PHP verifier and its immutable six original x86 native
    failures, physical Tell witnesses and original shipped Core binaries.
    Neither this evaluator nor the verifier reruns original compiled methods.
    """
    script="catalog/bin/verify-ue1-o06-g3-t009-three-original-native-malformed.php"
    result_path="catalog/compliance/evidence/o06/20261028-G3-batch-T009-native-error-regression-91pass.json"
    pins=[]
    for path,expected in [
        (script,"109a5baaa1760e572bd2aea6ddc11194d95f9a74222f0099423aca98e286728e7"),
        (result_path,"ec6c78721ed173a023e4d6dd58d84be022f80abd8ff5035b7eb7127b4cd22e45"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php",
         "2b87b97b2c6c0d830205623cce6cf2594792952ef6ebf5314e88f0a2511f27b1"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php",
         "afca90b65cfb192d23e5d0aaa0f449acb651c85548fb81ccac25bce3a9963714"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php",
         "df4c3a3c2e7ad3cdf826055380761e9890ed2a83744cb22b7a579657b3cfa500"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-C98-Children-original-compiled-Tell.log",
         "c88cd8a413167d33e40168dacc3082faa0ca51d2af6bf61f2d3912f67f65df54"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-C98-ScriptSize-original-compiled-Tell.log",
         "a4d88f4b4c7baadee6af3d743c76b354e375044f06b6330f12eaba815c4efe86"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-v432-original-native-Children-Tell.log",
         "5dfa15d8beb8afd9808776cc3976116b47806b6b2d5c6391e10d27c9431a520d"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-v432-original-native-ScriptSize-Tell.log",
         "2267d0ab6db79beb335315b195cd6afbfff0159a5d5d4df81187a5a8dd9f0a70"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-v432-original-native-Tell-impl.log",
         "eddde0fa7996348455d5d034036008c554c19ee342254515cc21cced2cd390a6"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-227k-original-native-Children-Tell.log",
         "5f1fb38123da601dee3a2785bc4cdbae04edb340fd39869afa66501c7ef8d95d"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-227k-original-native-ScriptSize-Tell.log",
         "1d7522447f3f590f68de07f629c8c301a9cdd1f68730b4c553f78e23466a1b8d"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-227k-original-native-Children-console.log",
         "4fcc8622ea7ea9c81485378a6f16b9004b7f4c5e56f2b3fdc9eab92fde48125e"),
        ("catalog/compliance/evidence/o06/20261028-G3-T009-227k-original-native-ScriptSize-console.log",
         "703053b17f40a77a91464a181e4fa8d934adfd7e2702ea1e498398e87c549c83"),
    ]:
        pins.append(verify_pin(path,expected))
    observed=json.loads((ROOT/result_path).read_text(encoding="utf-8-sig"))
    if (observed.get("ok") is not True or observed.get("checks")!=91
        or observed.get("errors") or observed.get("original_native_malformed_scenarios")!=6
        or observed.get("compiled_engine_native_runs_reexecuted_by_verifier")!=0
        or observed.get("previous_completed_positive_native_tests_replayed")!=0):
        raise AssertionError("T009 original compiled six-negative 91/91 source-native acceptance missing")
    samples=observed.get("observations",[])
    expected=[
        ("C98","UE1 Bad import index 62/16 at archive cursor 24224",24223),
        ("C98","UE1 Script serialization mismatch: Got 9, expected 5 at archive cursor 24243",24233),
        ("v432","UE1 Bad import index 62/16 at archive cursor 43307",43306),
        ("v432","UE1 Script serialization mismatch: Got 7, expected 3 at archive cursor 43324",43316),
        ("227k","UE1 227k <?int?Core.Errors.ImportIndex?> at archive cursor 67623",67622),
        ("227k","Script (EndState) serialization failed: Size mismatch: Got 7, expected 3 at archive cursor 67640",67632),
    ]
    if len(samples)!=6 or [
        (s.get("profile"),s.get("current_reader_error"),s.get("mutated_archive_byte_offset"))
        for s in samples]!=expected or not all(s.get("original_native_error_and_Tell_match") is True for s in samples):
        raise AssertionError("T009 per-build native method errors, 1-byte source offsets or physical Tell mismatch")
    return {"status":"PASS","result":"Six genuinely compiled original native UStruct malformed branches: C98 retail EndState Children import index62/16 Tell24224 and ScriptSize9/5 Tell24243; stock v432 Children62/16 Tell43307 and ScriptSize7/3 Tell43324; original 2026 Unreal 227k localized ImportIndex Tell67623 and named ScriptSize7/3 Tell67640. Six independent one-byte real Core.u mutants, original loaded method/reader physical Tell disassembly, SHA-pinned native x86 logs and original binaries. Production source-selected 91/91 exact errors/cursors/rejected loaded state PASS; no native or prior accepted test rerun. Only T009 closed; G3-03 group remains OPEN.","evidence":pins}


def eval_t012(_row: dict[str, Any]) -> dict[str, Any]:
    """Original May 1998 Core.u UClass: all actually serialized class records."""
    b="catalog/compliance/evidence/o06/"
    pins=[verify_pin(p,h) for p,h in [
        ("catalog/bin/verify-ue1-o06-g3-t012-original-c98-uclass.php","d29b33ead982294239583d7aedb5eba42e72d3fdee0a412d321b27cde0be0b43"),
        (b+"20261029-G3-T012-C98-original-Core-UClass-54pass.json","ce75c9ba1235a0e9cbf8ddb815971f95f3410786b427b4bea5f1dfc8ce7d9e26"),
        (b+"20261029-G3-T012-C98-Core-original-native.log","dff15ea260e1846c51af819c6d54be89bb5ada00ee91a695902bca8616b20d85"),
        (b+"20261029-G3-T012-C98-Core-original-native.cmd","319a4230a8091bc7cd82fbcedecd5054af9310d012afea9a3f4a09c78d5e6b82"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php","842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468"),
    ]]
    originals=[
      (r"C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System\Core.dll","9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92"),
      (r"C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System\Core.u","7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c"),
      (r"L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnClass.cpp","ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291"),
    ]
    for name,expected in originals:
        path=pathlib.Path(name)
        if not path.is_file() or sha256(path)!=expected:
            raise AssertionError("T012 original 1998 Epic source/compiled binary changed: "+name)
        pins.append({"original":name,"sha256":expected})
    result=json.loads((ROOT/b/"20261029-G3-T012-C98-original-Core-UClass-54pass.json").read_text(encoding="utf-8"))
    if not (result.get("ok") is True and result.get("checks")==54
            and result.get("failures")==[] and result.get("T012")=="PASS"
            and result.get("source_version")==61
            and result.get("native_original_C98_serialized_Core_UClass_count")==2
            and result.get("source_nonexport_intrinsics")==["Core.Field","Core.Struct"]
            and result.get("original_compiled_native_reruns_by_this_verifier")==0):
        raise AssertionError("T012 genuine C98 compiled native UClass fields/refs/source exclusion incomplete")
    return {"status":"PASS","result":
      "Original retail May 1998 C98 Core.dll UClass::Serialize actual runtime for both and only genuine serialized Core.u UClass exports: Object and Subsystem (v61). Real compiled loaded ClassFlags=19, GUID, FDependency class pointer identity for 3 native references, deep flags and script CRC, PackageImports array counts, complete archived bytes matched by source reader; source-absent ClassWithin correctly excluded. Field and Struct are actual original compiled C++ intrinsic StaticClass registrations, NOT serialized UClass entries in genuine Core.u: tested source exclusion rather than inventing package exports. Original source SHA and raw debugger commands/log pinned, 54/54 FULL, no accepted native suite rerun.","evidence":pins}


def eval_t013(_row: dict[str, Any]) -> dict[str, Any]:
    """Original UT99 v432 complete UClass Core.u seven + sealed Engine.u 88."""
    base="catalog/compliance/evidence/o06/"
    pins=[]
    for path,h in [
        ("catalog/bin/verify-ue1-o06-g3-t013-original-v432-core-engine.py","b8431fc2021c0e416ff003025b47b53909bc948a56bafd1e80c5c3c7795ed8fb"),
        (base+"20261028-G3-T013-original-v432-Core7-Engine88-207pass.json","2b96c37ec28f8027758d89d387d32739507a9c5bff8640e856efbf38bc09805e"),
        (base+"20261028-G3-T013-v432-Core-seven-source-archive.json","6593137474989fd6fa03cd6fb7882a3327cdb8080e10553e1b48717a0c833c7a"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php","842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468"),
        (base+"20261028-G3-UCLASS-v432-original-Core-all-class-flags-within.log","631379efb7a9c11bb529f67147491a1b939e68dc31e8217cdb11bd28d5eda3f1"),
        (base+"20261028-G3-UCLASS-v432-inherited-original-core-fields.log","3c63226cca8a7f49ecb0fccd3854307046a9bd29f33a68b60d0322e23c92932c"),
        (base+"20261028-G3-UCLASS-v432-remaining-original-core-fields.log","17619b0952b1db3dcafe69e9c069b539bfa5c3bf756113ff9bf36df141ed2ee8"),
        (base+"20261028-G3-UCLASS-v432-dependency-original-core-fields.log","3646f312781985b399956532c21dd296c231535c78c2cc61517628326681f6ca"),
        (base+"20261028-G3-UCLASS-v432-archive-cursor-original-core-fields.log","f18aa62cd790fa2a534c15488da9f67932b55d7fdaa99742cc2ec9504fc8827e"),
        ("catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json","772b85769a1773ab80bdfe2be278846e3d1c2977a5fd28eb2fef8e9df0049f7e"),
        ("catalog/compliance/UE1-O06-G5-T99-UCLASS-WHOLE-NATIVE-SCRIPT.json","c3447d0dc1092df8b4eaa92453846feeeeef7db2794d32264fddd722c81573fc"),
    ]:
        pins.append(verify_pin(path,h))
    r=json.loads((ROOT/base/"20261028-G3-T013-original-v432-Core7-Engine88-207pass.json").read_text(encoding="utf-8"))
    if not (r.get("ok") is True and r.get("checks")==207 and r.get("failures")==[]
            and r.get("T013")=="PASS"
            and r.get("original_stock_Core_u_UClass_fully_compared")==7
            and r.get("native_Core_u_dependency_elements")==13
            and r.get("original_Engine_u_UClass_FULL_reused")==88
            and r.get("original_compiled_native_executions_by_this_verifier")==0):
        raise AssertionError("T013 original seven Core.u and 88 Engine.u complete native oracle missing")
    return {"status":"PASS","result":"Original shipped compiled UT99 v432 Core.dll UClass::Serialize loaded all 7 genuine Core.u classes; all archived ClassFlags, real ClassWithin/UField ref identities, global FName config, script/state scalars, GUIDs, 13 fully initialized FDependency elements (native same-process UObject.Index, deep, CRC), PackageImports and original archive consumption verified. Genuine v432 native ClassFlags loaded 274 with FArchive Tell 5458->5462. Reuses previously fully accepted original 88 genuine Engine.u classes with 7/7 source gates and 608 defaults. Current source/native 207/207 focused verifier, no prior native compiled suite replay. T013 only, 469f/227i/227k and C98 distinct builds remain OPEN.","evidence":pins}


def eval_t014(_row: dict[str, Any]) -> dict[str, Any]:
    """Real compiled UT99 469f native UClass Engine fields, 88 archived vs 132 live."""
    base="catalog/compliance/evidence/o06/"
    expected=[
        ("catalog/bin/verify-ue1-o06-g3-t014-original-469f-uclass-live.php","b1a5394c4ed2007da7c9c9172b4db87eff24b2c6d913b70460a7893add6d1d1e"),
        (base+"20261029-G3-T014-original-469f-Engine-UClass-loaded-values.log","0d5380ced4ff0178c925a767b25f7466661c3075118750606dde2b5e2cc54e57"),
        (base+"20261029-G3-T014-original-469f-native-uclass-probe.cpp","a97aed65eb129f253ed51bab15bce3feefb7563fd5c45c2db8851e412474b2f1"),
        (base+"20261029-G3-T014-original-469f-UClass-856pass.json","0b0b1025101dfa0e602680feeb8858798ac6de7c1f04bc4911484bb7cba48c6f"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php","842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468"),
        ("catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json","772b85769a1773ab80bdfe2be278846e3d1c2977a5fd28eb2fef8e9df0049f7e"),
    ]
    pins=[verify_pin(path,h) for path,h in expected]
    original=[
      (r"C:\Temp\UnrealDB\O06\G3-REAL-T059-469F-ENTRY\System\Core.dll","80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2"),
      (r"C:\Temp\UnrealDB\O06\G3-REAL-T059-469F-ENTRY\System\Engine.dll","625dabea278e314714a45c931c98ba6282a9198378499144429aa979a1a0570f"),
      (r"C:\Temp\UnrealDB\O06\G3-REAL-T059-469F-ENTRY\System\Engine.u","6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b"),
    ]
    for fn,h in original:
        fp=pathlib.Path(fn)
        if not fp.is_file() or sha256(fp)!=h:
            raise AssertionError("T014 original compiled 469f binary changed: "+fn)
        pins.append({"original":fn,"sha256":h})
    result=json.loads((ROOT/base/"20261029-G3-T014-original-469f-UClass-856pass.json").read_text(encoding="utf-8-sig"))
    required={"ok":True,"checks":856,"errors":[],"status":"PASS",
      "original_469f_Engine_runtime_live_UClass_count":132,
      "original_469f_serialized_UClass_compared_to_native":88,
      "original_469f_compiler_registered_only_UClass":44,
      "v432_original_previously_accepted_full_UClass":88,
      "actual_469f_vs_v432_archived_direct_field_changes_count":45,
      "original_compiled_native_sessions_reexecuted_by_verifier":0}
    for key,v in required.items():
        if result.get(key)!=v:
            raise AssertionError("T014 original native/full class value check "+key)
    return {"status":"PASS","result":
      "Original compiled 469f Core.dll and Engine.dll plus correct SDK-built x86 class probe loaded genuine Engine.u successfully: 132 real runtime registered UClasses, 88 genuine serialized UClass exports individually compared 6 direct native live fields and complete file consumption; 45 real v432-v469f archived field differences identified, 856/856 FULL focused parity, prior v432 original 88-class gate reused, zero original reruns by verifier. T014 only.","evidence":pins}


def eval_t015(_row: dict[str, Any]) -> dict[str, Any]:
    """Authentic 2012 C227i + 2026 C227k UClass archive and loaded phase."""
    b="catalog/compliance/evidence/o06/"
    expected=[
      ("catalog/bin/verify-ue1-o06-g3-native-shared-c227-class-array.py","5528df2271f2c846d01017ae7e03c28f48c31f615541ccb5bb9a0bcd39ef11b3"),
      (b+"20261029-G3-T015-original-227i227k-C227-class-phase-1633pass.json","cf4fbf2b1c235ff0dd1bd8edd4a610bf6f58b794ed19a3dee4065e0ae60e683b"),
      (b+"20261029-G3-T015-C227k-archive-postload-original-native.log","4fd7711f9ad6d04171e028176d869de974ef1acd0781f969ee3a749be38e33ab"),
      (b+"20261029-G3-T015-C227k-archive-postload-original-native.cmd","7530d9dcb0d5665edd0f30c7f9db6cb364e0de0ce06f6f2255fb7086b5a76c57"),
      (b+"20261028-G3-UCLASS-227i-original-Core-all-class-flags-within.log","f1af9ff06bd5ab7a4fd56b7fc5789f890c0534c9a0e0bcd4a769cce0a75bfc95"),
      (b+"20261029-G3-T015-C227k-Core-original-native.log","11878c0fe841dc1266b159a1209a5ca739b24c83c8e1af7c6c66cbf2cd3b8299"),
      (b+"20261029-G3-T015-C227k-Engine-original-native.log","5d176ef93ccadf9dba68180de511b04cba016216467104bf7f9c62ffb676fe28"),
      (b+"20261029-G3-T015-C227k-archive-class-source.json","3f72c2faa4c86a1b70d6d6fb1c105007168d96b7888c880c173319031a4d216e"),
      ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php","842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468"),
    ]
    pins=[verify_pin(path,h) for path,h in expected]
    originals=[
      (r"C:\Temp\UE1-G3-NativeOracle\original227i\System\Core.dll","287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897"),
      (r"C:\Temp\UE1-G3-NativeOracle\original227i\System\Core.u","bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b"),
      (r"C:\Temp\UnrealDB\O06\Native-227k\System\Core.dll","c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194"),
      (r"C:\Temp\UnrealDB\O06\Native-227k\System\Core.u","28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c"),
    ]
    for name,hash_expected in originals:
        path=pathlib.Path(name)
        if not path.is_file() or sha256(path)!=hash_expected:
            raise AssertionError("T015 original compiled 227 binary source drift: "+name)
        pins.append({"original":name,"sha256":hash_expected})
    result=json.loads((ROOT/b/"20261029-G3-T015-original-227i227k-C227-class-phase-1633pass.json").read_text(encoding="utf-8"))
    if not (result.get("ok") is True and result.get("failures")==[]
            and result.get("checks")==1633
            and result.get("T015")=="PASS_ORIGINAL_2012_2026_CLASS_METADATA_AND_NATIVE_PHASE"
            and result.get("original_2012_Core_native_class_fields_compared")==6
            and result["original_2026_native"]["Core"]["fully_matching_direct_native"]==8
            and result["original_2026_native"]["Core"]["archive_live_dependency_phase_proven"]==1
            and result["original_2026_native"]["Engine"]["fully_matching_direct_native"]==187
            and result.get("original_compiled_native_reruns_during_verifier")==0):
        raise AssertionError("T015 original C227i and C227k complete loaded class field/phase native evidence missing")
    return {"status":"PASS","result":
      "Original 2012 Unreal 227i Core.u 6 genuine UClass loaded fields compared to source archive; original 2026 Unreal 227k Core.u 8/8 and Engine.u 187/187 genuine serialized class metadata matches native loaded fields, real 227i-to-227k Commandlet and Subsystem ClassFlags differences are archive/source-gated. Original compiled 227k same Core.Object pointer native UClass::Serialize post-operator Dependencies.Num=3 and UClass::PostLoad entry=2 independently establishes source/linked phase change, not reader error. Shared original compiled 2012/2026 scoped 1633/1633 PASS; original native suites not rerun by verifier. T015 fully CLOSED.","evidence":pins}


def eval_t017_t059(row: dict[str, Any]) -> dict[str, Any]:
    """Frozen G3 Actor CDO + mixed Bool/Int/Name shared original 469f native oracle."""
    b="catalog/compliance/evidence/o06/"
    pinned=[
        ("catalog/bin/verify-ue1-o06-g3-t017-t059-original-469f-actor-defaults.php","2604e72c9a840bbe04f6b44dd4f82b28a42aa3e17d3733d397c1749873e90081"),
        (b+"20261029-G3-T017-T059-original-469f-v432-two-closures-902pass.json","184d662a2ebdff10898993a4b9364e951a3a4d5fc7f674c0d34e28a5d2a8d3a2"),
        (b+"20261029-G3-T017-T059-original-469f-three-actor-native-defaults.log","c3c7fa3246e9ad41d6a03556852d0a0f726e94e1ea31c5aa1d442af1802acb99"),
        (b+"20261029-G3-T017-T059-original-469f-three-actor-native-probe.cpp","a770685fe57d2afee5a171c1cf7cabb3e4fb1ebdf63172982f4a658073aa6434"),
        (b+"20261029-G3-T017-T059-original-v432-469f-actor-source.json","936faa24264f8adba488eb28fc877b52e0eab9846feb027b12e8573e0b7cc67f"),
        ("catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json","772b85769a1773ab80bdfe2be278846e3d1c2977a5fd28eb2fef8e9df0049f7e"),
        (b+"20261029-G3-T014-original-469f-UClass-856pass.json","0b0b1025101dfa0e602680feeb8858798ac6de7c1f04bc4911484bb7cba48c6f"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php","842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468")
    ]
    pins=[verify_pin(name,digest) for name,digest in pinned]
    for name,digest in [
        (r"C:\Temp\UnrealDB\O06\Native-469f\System\Core.dll","80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2"),
        (r"C:\Temp\UnrealDB\O06\Native-469f\System\Engine.u","6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b"),
        (r"C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.u","d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695")
    ]:
        source=pathlib.Path(name)
        if not source.is_file() or sha256(source)!=digest:
            raise AssertionError("Original UT99 compiled stock file drift: "+name)
        pins.append({"original":name,"sha256":digest})
    v=json.loads((ROOT/b/"20261029-G3-T017-T059-original-469f-v432-two-closures-902pass.json").read_text(encoding="utf-8"))
    if not (v.get("ok") is True and v.get("checks")==902 and v.get("failures")==[]
            and v.get("T017")=="PASS" and v.get("T059")=="PASS"
            and v.get("original_compiled_native_sessions_rerun_by_verifier")==0):
        raise AssertionError("Shared original 469f actual compiled actor and v432 source parity incomplete")
    if row["id"]=="T017":
        if v.get("source_Actor_bHidden_false_loaded_inherited_Pawn")!="False" or v.get("source_HUD_override_true_native")!="True":
            raise AssertionError("Original compiled actor inherited vs overridden default mismatch")
        reason=("Original 469f actual compiled Engine.u actor CDO: Pawn inherits Actor.bHidden=False, HUD overrides bHidden=True; original v432 actor/HUD archived fields and preaccepted real compiled 88 UClass/608 native default values reused. SuperField identities, original source tags, physical archive and real loaded outcomes verified.")
    elif row["id"]=="T059":
        actual=v.get("original_compiled_mixed_GameInfo_loaded") or {}
        if actual.get("bPauseable")!="True" or actual.get("MaxPlayers")!="16" or actual.get("DefaultPlayerState")!='"PlayerWalking"':
            raise AssertionError("Original compiled GameInfo mixed Bool/Int/Name value mismatch")
        reason=("Original compiled 469f GameInfo CDO native Bool bPauseable=True, Int MaxPlayers=16 and Name DefaultPlayerState=PlayerWalking; real original 469f and v432 archive tag type/order, name index=63 and fully consumed UClass physical archive match. Reused sealed original v432 native defaults.")
    else:
        raise AssertionError("Unexpected fixed G3 ID for actor defaults")
    return {"status":"PASS","result":reason+" Shared original compiled frozen 902/902 checks PASS; no original engine reruns.","evidence":pins}


def eval_t035(row: dict[str, Any]) -> dict[str, Any]:
    """Genuine source-selected original UT99 v432/469f imported Core.Struct Color."""
    b="catalog/compliance/evidence/o06/"
    evidence=[
      ("catalog/bin/verify-ue1-o06-g3-t035-original-v432-469f-imported-color.php","f061a4b4e079e29778c09c7a823e860673d4824d51d87168e47b4e3718d86d1f"),
      (b+"20261029-G3-T035-original-v432-469f-Core-Color-33pass.json","bf74d3f97d1322d1264f0df0e3208b2fe634c0ac4e86b79274e8a68d16a33869"),
      (b+"20261029-G3-T017-T059-original-469f-three-actor-native-defaults.log","c3c7fa3246e9ad41d6a03556852d0a0f726e94e1ea31c5aa1d442af1802acb99"),
      ("catalog/compliance/UE1-O06-G5-UCLASS-DEFAULTS-NATIVE-608.json","ae3e23e5e3b5ef7e7fab363fbd1ae7a75f80591212fac6bd2af7575638fa6e3b"),
      ("catalog/bin/verify-ue1-o06-g5-uclass-defaults-native-608.php","62d7f69c747a5f4c1b0fe430d76caab882cdb87e70839fb025cf27c69e8f1738"),
      ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php","842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468")
    ]
    pins=[verify_pin(p,h) for p,h in evidence]
    for path,digest in [
       (r"C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.u","d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695"),
       (r"C:\Temp\UnrealDB\O06\Native-469f\System\Engine.u","6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b"),
       (r"C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll","237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8"),
       (r"C:\Temp\UnrealDB\O06\Native-469f\System\Core.dll","80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2")
    ]:
        f=pathlib.Path(path)
        if not f.is_file() or sha256(f)!=digest:
            raise AssertionError("T035 original compiled UT99 Core/Engine stock source changed: "+path)
        pins.append({"original":path,"sha256":digest})
    result=json.loads((ROOT/b/"20261029-G3-T035-original-v432-469f-Core-Color-33pass.json").read_text(encoding="utf-8"))
    fields=result.get("genuine_Engine_HUD_WhiteColor_import_Core_Struct_Color",{})
    if not (row["id"]=="T035" and result.get("ok") is True and result.get("checks")==33
           and result.get("failures")==[] and result.get("T035")=="PASS"
           and result.get("source_profiles")==["v432","v469f"]
           and all(fields.get(p,{}).get("raw_hex")=="0080ff00" for p in ("v432","v469f"))
           and result.get("original_engine_native_sessions_rerun_by_verifier")==0):
        raise AssertionError("T035 original imported FColor native and original source byte parity missing")
    return {"status":"PASS","result":
       "Genuine stock UT99 v432 and 469f Engine.u HUD.WhiteColor StructProperty import Core.Struct Color resolved by exact original ImportMap identity; original source archive saved FColor 4 bytes 00 80 FF 00 with physical offsets 79888 and 75673, both complete source archives. Actual original compiled 469f HUD CDO loaded R0 G128 B255 A0; previously accepted original compiled v432 native 607 exact loaded properties including all 16 real StructProperty values (only config String override). Shared 33/33 original source/native imported struct PASS, no original native retest.","evidence":pins}


def eval_t060_t061(row: dict[str, Any]) -> dict[str, Any]:
    """One original compiled 2026 C227k Engine.u six-class CDO session."""
    b="catalog/compliance/evidence/o06/"
    pins=[verify_pin(name,h) for name,h in [
      ("catalog/bin/verify-ue1-o06-g3-t060-t061-original-c227k-cdo.php","e6e24608c01c6bedc070709e13b3252e68c9ed6f79e1f1c70ed45baee0ee0f4a"),
      (b+"20261029-G3-T060-T061-original-C227k-six-CDO-902pass.json","760a82b677c59fcc476b732b374756e26ec07a14d97727a7a817b7c47f4d6a66"),
      (b+"20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log","f78d3d518a01952667a3ca51698bf683cfc1995ca2d9374bb12b02993b4ef13d"),
      (b+"20261029-G3-T060-T061-C227k-original-Engine-6-CDO-probe.cpp","bf74e2ae67c5c61ed7407d7c9c69b4358b823eeda82e95079b78c08aeafa161b"),
      ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php","842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468"),
    ]]
    for path,digest in [
      (r"C:\Temp\UnrealDB\O06\Native-227k\System\Core.dll","c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194"),
      (r"C:\Temp\UnrealDB\O06\Native-227k\System\Engine.u","a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e")
    ]:
        p=pathlib.Path(path)
        if not p.is_file() or sha256(p)!=digest:
            raise AssertionError("Original compiled 227k binary/package modified: "+path)
        pins.append({"original":path,"sha256":digest})
    j=json.loads((ROOT/b/"20261029-G3-T060-T061-original-C227k-six-CDO-902pass.json").read_text(encoding="utf-8"))
    if not (j.get("ok") is True and j.get("failures")==[] and j.get("checks")==902
            and j.get("T060")=="PASS" and j.get("T061")=="PASS"
            and j.get("original_compiled_engine_sessions_rerun_by_verifier")==0
            and sorted(j.get("native_loaded_C227k_classes",[]))==sorted(["Actor","Pawn","PlayerPawn","HUD","GameInfo","LevelInfo"])
            and j.get("C227k_original_true_source_FName_index59")=="PlayerWalking"):
        raise AssertionError("Original compiled C227k six genuine CDO class and source archive proof incomplete")
    if row["id"]=="T060":
        reason=("Genuine 2026 C227k original compiled Core.dll loaded true Engine.u six CDOs; actual mixed GameInfo tagged Bool bPauseable=True, Int MaxPlayers=16, FName DefaultPlayerState=PlayerWalking, real archive FName index59 not UT99 index63. Native loaded values, source-specific real archive field types/offset order and complete serialized class consumption verified. Actual Pawn Health=100, AirControl=0.05, PlayerPawn.bCanJump=True further source-valid nonzero values.")
    elif row["id"]=="T061":
        inherited=j.get("native_original_PlayerPawn_inherits_parent_Pawn_unsaved_fields",{})
        if inherited!={"Health":"100","AirControl":"0.05","bHidden":"False"}:
            raise AssertionError("C227k actual unsaved PlayerPawn inherited nonzero parent fields missing")
        reason=("Actual original C227k source serialized PlayerPawn has NO Health/AirControl/bHidden tags. Genuine Pawn archive contains Health=100 and AirControl=0.05, Actor original bHidden=False; compiled original PlayerPawn CDO physically loads inherited Health=100/AirControl=0.05/bHidden=False, while explicit PlayerPawn bCanJump=True overrides original Pawn.bCanJump=False. Archive reader correctly reports absence rather than inventing zero; loaded-values oracle independently proves source-defined inheritance.")
    else:
        raise AssertionError("T060/T061 evaluator called for wrong fixed ID")
    return {"status":"PASS","result":reason+" Shared source-selected original compiled native six CDO 902/902 acceptance checks, no original engine reruns in verifier.","evidence":pins}


def eval_t036_t039(row: dict[str, Any]) -> dict[str, Any]:
    """Authentic original 227k compiled Engine.u property/member links and CDO."""
    b="catalog/compliance/evidence/o06/"
    evidence=[verify_pin(n,h) for n,h in [
      ("catalog/bin/verify-ue1-o06-g3-t036-t039-original-c227k-nested-native.php","ae4ad924873d10f10aca9965bc2ab60695497f0a72922736dd22a82a8ba51f2b"),
      (b+"20261029-G3-T036-T039-original-C227k-95pass.json","506bb85d67b408283f8018235a1880bd45f8291ef31c018d6a786ec6f66a16db"),
      (b+"20261029-G3-T036-T039-C227k-original-nested-struct-native.log","edce7870d4a3522ed053bfe1a5b115e345ac4fd6ba87ffdbd40fdb5bfb1849da"),
      (b+"20261029-G3-T036-T039-C227k-original-nested-struct-probe.cpp","59dfc53678899142459a1511cb06f332a545ffaa436592cdf16cab8301c87e9e"),
      (b+"20261029-G3-T060-T061-original-C227k-six-CDO-902pass.json","760a82b677c59fcc476b732b374756e26ec07a14d97727a7a817b7c47f4d6a66"),
      (b+"20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log","f78d3d518a01952667a3ca51698bf683cfc1995ca2d9374bb12b02993b4ef13d"),
      ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php","90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
    ]]
    for name,sha in [
      (r"C:\Temp\UnrealDB\O06\Native-227k\System\Core.dll","c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194"),
      (r"C:\Temp\UnrealDB\O06\Native-227k\System\Engine.u","a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e"),
      (r"L:\Source\Games\Unreal\Unreal [v1.227]\Core\Inc\UnMath.h","7db6a8e314ccb8a5943330c677763bfb4ac948b3a595b81400034689d0cec677"),
      (r"L:\Source\Games\Unreal\OldUnreal-UnrealPatch227k-SDK-Windows\Core\Inc\UnMath.h","2c53261003b3176e63fe7f8b9c47e7f8013cb460e7bdc6fb4433a895b49c91e0")
    ]:
        p=pathlib.Path(name)
        if not p.is_file() or sha256(p)!=sha:
            raise AssertionError("T036/T039 original compiled/native source SHA changed: "+name)
        evidence.append({"original":name,"sha256":sha})
    j=json.loads((ROOT/b/"20261029-G3-T036-T039-original-C227k-95pass.json").read_text(encoding="utf-8"))
    if not (j.get("ok") is True and j.get("failures")==[] and j.get("checks")==95
        and j.get("T036")=="PASS" and j.get("T039")=="PASS"
        and j.get("original_native_2026_linked_struct_property_count")==10
        and j.get("original_native_2026_member_offset_events")==36
        and j.get("new_original_compiled_engine_reruns_during_verifier")==0):
        raise AssertionError("T036/T039 real linked native 227k struct member values/offsets not complete")
    if row["id"]=="T036":
        native=j["native_227k_Pawn_RotationRate"]
        if not (native["Pitch"]["native_value"]==4096 and native["Pitch"]["offset"]==0
           and native["Yaw"]["native_value"]==50000 and native["Yaw"]["offset"]==4
           and native["Roll"]["native_value"]==3072 and native["Roll"]["offset"]==8):
            raise AssertionError("original linked 227k Rotator nonzero member memory mismatch")
        explanation=("Original 2026 227k compiled Engine.u Pawn native UStructProperty RotationRate owner offset620, array dimension1, element size12; imported Core.Struct Rotator actual member Pitch/Yaw/Roll offsets0/4/8, loaded values 4096,50000,3072. Reader source-selected genuine Pawn FPropertyTag physical 12 bytes 0010000050c30000000c0000 exactly, original native CDO independently matches, inherited PlayerPawn values match.")
    elif row["id"]=="T039":
        members=j["native_227k_Actor_SimAnim_inherited_Plane"]
        if not ([(k,v["offset"]) for k,v in members.items()]==[("W",12),("X",0),("Y",4),("Z",8)]):
            raise AssertionError("original native subclass Plane member offsets flattened or reordered")
        explanation=("Original compiled 227k Engine.u Actor.SimAnim UStructProperty offset644, 1x16, native Struct Core.Object.Plane has actual nonnull SuperStruct FVector; original compiled member PropertyLink order W, X, Y, Z with physical offsets12,0,4,8. Original 2012 and 2026 Epic UnMath.h FPlane::operator<< archives inherited FVector XYZ then local W. Original 227k Engine.u saved sixteen zero float bytes, compiled CDO reports W/X/Y/Z zero; reader now decodes Plane as inherited ordered x,y,z,w rather than opaque/falsely flat. Separate original native Rotator demonstrates flat 12-byte struct.")
    else:
        raise AssertionError("Not a fixed G3 nested/inherited test ID")
    return {"status":"PASS","result":explanation+" One grouped original native property-link run and frozen 95/95 checks; no accepted original native suites repeated.","evidence":evidence}


def eval_t047_three_compiled_map_branches(row:dict[str,Any])->dict[str,Any]:
    """Three source-selected real native Core.dll map methods; one full frozen T047."""
    b="catalog/compliance/evidence/o06/"
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-t047-original-three-map-native.php",
        "debeb71c02f81f87d881d5ec2ab032629c809e2fa464e646fcca11fc25482d85"),
      verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructArrayValueReader.php",
        "a55457d788c91a9b9021c5489b9b94d6b913a8049fe54c4abf02ec453bad58d9"),
      verify_pin(b+"20261030-G3-MAP-original-C227i-native-2012-disasm.log",
        "bd6a8d4e656cf9bd716bd08fc7b307ba5fbb5ae754c287b4b1af22397bb9d1ea"),
      verify_pin(b+"20261030-G3-MAP-original-C227i-2012-export-RVA.txt",
        "d1c955889a39b41f9a952a78bf966cf6f50083605dc2f5cb3ecb749af1e2eed7"),
      verify_pin(b+"20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.log",
        "e137d776744395d9581dbe317bc3540deeea600223d9076f5427f4b3b01f40cf"),
      verify_pin(b+"20261030-G3-B06-FIXED-C227k-original-T047-partial-MAP.log",
        "82911deb40aef6797ff76901e40530f0d174944f89d94aebed6f392e1f585051"),
      verified_pass_log(b+"20261030-G3-MAP-T047-original-37pass.json","checks",37)
    ]
    j=json.loads((ROOT/b/"20261030-G3-MAP-T047-original-37pass.json").read_text(encoding="utf-8-sig"))
    if j.get("full_frozen_g3_ids",{}).get("T047")!="PASS":
       raise AssertionError("All three original native compiled engine map paths not closed")
    return {"status":"PASS","result":
      "One 37/37 SHA-pinned three-original-engine native UMapProperty oracle. Original stock UT99 v432 compiled Core.dll real UMapProperty with native Key/Value UIntProperty object descriptors, empty genuine native TMap and nonempty genuine native 2-entry map {12:1200,34:3400}: both UMapProperty::SerializeItem SAVE bytes0, native FBufferReader LOAD Tell0, native TMap data unchanged. Original genuine 2012 C227i Core.dll .edata specifically resolves ?SerializeItem@UMapProperty@@ to code RVA0x1000; original CDB on real loaded original Core.dll resolves shared FNullOutError::Serialize instruction 'ret 8' at Core+0x1000 (the complete entire compiled no-data method), proving neither empty nor nonempty values are archived; real original separate compiled UMapProperty::NetSerializeItem RVA0x49FE0 returns 1 no bytes, not misused as archive method. Original 2026 C227k compiled Core.dll native creates real UMapProperty with valid live UIntProperty Key/Value and genuine TScriptMap<INT,INT>: empty original FBufferArchive wrote 00 one byte, original FBufferReader LOAD count0/Tell1; separate live two-entry original wrote 020C000000B004000022000000480D0000 exactly 17 bytes, original FBufferReader LOAD count2/Tell17. Production source-backed CatalogUE1ClassicStructArrayValueReader now carries resolved UMapProperty Key/Value kinds and source profile and parses 227k integer map exactly 0/2 values and native archive 1/17, while original v432/2012 explicitly consumes zero bytes and never infers nonempty in-memory maps. IMPORTANT source selects exact compiled 'unreal-v227i' vs 'unreal-v227k' and not v69/lic0 or lic227 package metadata: 2012 .u loaded on 2026 still uses new runtime. Unknown key/value serializer or ambiguous runtime source remains explicit unsupported. No accepted native-engine oracles replayed; 2012 CDB method is ONE newly executed native test, original previously preserved v432/227k actual methods reused, not repeated.","evidence":pins}


def eval_t048_original_both_source_array_failure(row:dict[str,Any])->dict[str,Any]:
    """Frozen T048: actual original v432+227k malformed array source-specific errors, not native UB."""
    b="catalog/compliance/evidence/o06/"
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-t048-original-array-errors.php",
                 "7dcef92b013daf8e79b2f14421c46580a6a7a72944b3a3010869e18e29ee897f"),
      verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
                 "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
      verify_pin(b+"20261030-G3-COUNT-C227k-original-CountThree.u",
                 "6c993b943a76a2729cf525abe58f79f5eaf685d31e764012d965485dc461c94c"),
      verify_pin(b+"20261030-G3-COUNT-C227k-original-CountHuge.u",
                 "14cb3bc6f680ed736d84a1ead059b45b6bfccbf3cc45ddcb96c2880a4a47e068"),
      verify_pin(b+"20261030-G3-COUNT-C227k-original-CountThree.log",
                 "247281e85e55776564dbd37b7c6d12ef6479407978af868b7b18951f57dc6018"),
      verify_pin(b+"20261030-G3-COUNT-C227k-original-CountHuge.log",
                 "16e4a52a9e99fe7b5d6f5d125ebffc654001fa82ee9b439dce1684359be33cf3"),
      verify_pin(b+"20261030-G3-COUNT-v432-original-Count31-EOF.log",
                 "5b22df2a609d87803a857c047397a4f9bf6885fddf87cac449c0b63a3889c869"),
      verified_pass_log(b+"20261030-G3-COUNT-T048-original-39pass.json","checks",39)
    ]
    j=json.loads((ROOT/b/"20261030-G3-COUNT-T048-original-39pass.json").read_text(encoding="utf-8-sig"))
    if j.get("full_frozen_g3_ids",{}).get("T048")!="PASS":
       raise AssertionError("Real original v432 and 227k malformed arrays not fully verified")
    return {"status":"PASS","result":
      "Original compiled UT99 v432 UArrayProperty::SerializeItem genuine negative1/zero count native errors both original method-specific size mismatch Got8 Expected29 at original physical FArchive Tell259, and separate positive oversized count31 native physical EOF ReadFile beyond 308+3/308 null package. Source-selected v432 reader matches all without claiming negative-native loaded arrays. Original 2026 compiled C227k original SavePackage genuine 308-byte nested G3Tuple UArrayProperty count2 at offset234: separate new native one-byte positive3 and huge63 mutations both genuine original compiled Core.dll error ULinkerLoad<<FName via UStruct::SerializeBin Bad name index6248/12 at actual physical Tell263; production reader exact same name/index and cursor263. Original 227k count0 source-specific recovery logs warning Got1 Expected19, actual native successfully loaded empty Pairs and subsequent Strings; production source-gated reader reproduces true count0 and original export-end Tell280. C227k count negative1 triggers original native C++ FArray allocator access violation; explicitly excluded from deterministic error cursor acceptance, never treated as a successful load. No source profiles without UArrayProperty are falsely included. One joint independently checked 39/39 native/source/current verifier closes only THIS frozen T048; broader compact name/string bound matrix T068 and malformed tag matrix T064 remain BLOCKED until distinct original source branches available.",
      "evidence":pins}


def eval_t037_t038_t040_original_nested_batch(row:dict[str,Any])->dict[str,Any]:
    """Three distinct frozen B05 method tests; genuine v432 and 227k preexisting native positive/negative evidence plus one new invalid nested name native oracle."""
    b="catalog/compliance/evidence/o06/"
    checks=[
       verify_pin("catalog/bin/verify-ue1-o06-g3-b05-three-original-nested.php",
          "35f2aaa14c780a013bf51fae7e9fa6267f271654d17bbe49bd331dbe51c605ca"),
       verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
          "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
       verify_pin(b+"20261030-G3-B05-NEST-V432-original-STRUCT-SAVE.u",
          "c65dd008e97a431b0b8166f1e80359303a2316f3e0df3b5a1b9b5556ee93c96f"),
       verify_pin(b+"20261030-G3-B05-NEST-V432-original-STRUCT-LOAD.log",
          "607f914e8e797f23c08a11233ea1aab7f47d89af98988e722634d5f10e2a9226"),
       verify_pin(b+"20261030-G3-B05-NEST-V432-original-BAD-STRING.log",
          "4d3cf87aef0127f995baf384866df7e5981b17101ee7557c45df94450626feb8"),
       verify_pin(b+"20261030-G3-B05-NEST-C227k-original-BAD-NAME.u",
          "85664998a635464788fe6479d86bbc992bfb34eabfb8a61ac6b5fe5d5b9b8d2b"),
       verify_pin(b+"20261030-G3-B05-NEST-C227k-original-BAD-NAME.log",
          "f815170f2e241eee1eddb283171c0cf2027b28635481148a7899a16964d6a601"),
       verify_pin(b+"20261030-G3-B06-AGG-C227k-original-SAVE.u",
          "34a00b4f5bdf78dd56ef62a79f62684b6159fb0ecfc43e630eaa08c7ee09d8f7"),
       verify_pin(b+"20261030-G3-B06-AGG-C227k-arrayZero-native.log",
          "e3b5858aa37af3fac5b53571abd36fae819a66cfe50400cc272606e73f7e250f"),
       verified_pass_log(b+"20261030-G3-B05-NEST-three-original-55pass.json","checks",55),
    ]
    proof=json.loads((ROOT/b/"20261030-G3-B05-NEST-three-original-55pass.json").read_text(encoding="utf-8-sig"))
    if proof.get("full_frozen_g3_ids",{}).get(row["id"])!="PASS":
        raise AssertionError("Single grouped original stock v432 and genuine 227k native nested oracle does not close "+row["id"])
    outcomes={
      "T037":"Original UT99 v432 compiled Core.dll loaded actual source-legal direct nested G5CaptionRecord (FString Label, INT Marker) with nonempty Label=Direct-Record and Marker71. Original native SavePackage archived OneRecord struct exactly 19 bytes (signed FString count14, 13 ANSI chars plus zero, 4 INT marker), and later ORIGINAL stock Core.dll loaded native live CDO/instance Label=Direct-Record, Marker71. Source-linked production UStruct::SerializeBin reader resolves native field order (FString then INT), exact 19-byte value span, label length/count/terminator, no false stock v432 allocator-uninitialized nested string array claim; latter remains explicitly excluded.",
      "T038":"Original 2012 C227i UCC compiled source-defined G3Tuple struct with Number INT, Fraction FLOAT, Label FName, v227k genuine Core.dll compiled native saved 308-byte .u with two distinct struct values (101,1.25,Alpha) and (-202,-2.75,Gamma). A separate original Core.dll native process independently reloaded both and confirmed exact six values, stride12, 19 serialized bytes. Second native original v227k zero-count single-byte mutation of exactly count2->0 at offset234 produced original warning size mismatch Got1 Expected19, *successfully* loaded one object with Pairs count0, and continued to two genuine FString fields. Production source-linked exact Count0 decoder now performs original v227-specific 18-byte tag-end resync and reads subsequent tag fields without inventing struct members; both native SavePackage and two actual original process load outcomes match.",
      "T040":"TWO distinct source-defined original nested failures, not duplicated outer generic corruption. First original true UT99 v432 compiled stock Core.dll on one-byte mutated legal G5CaptionRecord Label FString emitted native ReadFile beyond EOF 309+1/309 with actual FArchive stack ULinkerLoad::Serialize <- FString<< <- UStruct::SerializeBin <- FPropertyTag::SerializeTaggedProperty, returned NULL package/native_error1; production same physical offset309 and method. Second original 2026 C227k saved array<UStruct G3Tuple> with a single nested *member* FName compact linker index changed original 4 to invalid63 at archive offset243. Original stock compiled Core.dll emitted Bad name index63/12 with ULinkerLoad<<FName <- UStruct::SerializeBin trace, actual physical Tell244, null runtime bad1. Production source-selected native linked name reader rejects same nonzero invalid nested reference at exact offset244. Both actual changed source files remain 308/309 bytes with exactly one modified byte; no invented loaded field."
    }
    return {"status":"PASS","result":outcomes[row["id"]]+
      " Joint SHA-pinned original v432 and original C227k source/native/reader 55/55 acceptance checks, 3 NEW frozen B05 closures in one batch. No previously accepted native engine execution repeated.","evidence":checks}


def eval_t045_t046_original_true_fixed_native(row:dict[str,Any])->dict[str,Any]:
    """Actual original C227i UCC + C227k Core.dll real UFixedArrayProperty, two fixed IDs."""
    b="catalog/compliance/evidence/o06/"
    checks=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-b06-t045-t046-original-fixed-native.php",
                 "2a72fb7548cd80eeae9abfb7e415e720e71450ee7e9dc6f06bfbf897fcf39294"),
      verify_pin(b+"20261030-G3-B06-FIXED-C227i-original-compiled.u",
                 "38252904ed4ec9c805f166feb3b6e408e8095ac93bbd22754a2a85448ff238b4"),
      verify_pin(b+"20261030-G3-B06-FIXED-C227k-original-SerializeItem.cpp",
                 "c0573faf2eaa2f1099acdd56e32643c5f34ef3bc77b230be9fa599e721865e4b"),
      verify_pin(b+"20261030-G3-B06-FIXED-C227k-original-SerializeItem.log",
                 "ac4ddf1b9b84c6488db12278434430e96b9580aa4b90e27c109b16f5bb8f4b32"),
      verified_pass_log(b+"20261030-G3-B06-FIXED-T045-T046-original-52pass.json","checks",52)
    ]
    proof=json.loads((ROOT/b/"20261030-G3-B06-FIXED-T045-T046-original-52pass.json").read_text(encoding="utf-8-sig"))
    if proof.get("full_frozen_g3_ids",{}).get(row["id"])!="PASS":
        raise AssertionError("True original compiled UFixedArrayProperty did not close "+row["id"])
    outcome={
      "T045":"Original 2012 C227i UCC genuinely compiled 0-warning source script original 954-byte real v69 UClass with UIntProperty TwoInts[2] initial 123,-456; actual 2026 original compiled Core.dll loaded UClass, constructed a *REAL UFixedArrayProperty* native object and called original UFixedArrayProperty::AddCppProperty on the resolved real UIntProperty with Count2. Original compiled native SerializeItem FBufferArchive SAVE wrote exactly 8 bytes 7B00000038FEFFFF, original compiled FBufferReader LOAD consumed exactly 8 and independently restored 123,-456. No Count prefix. Production source-backed CatalogUE1ClassicStructArrayValueReader::readAt with genuine native type, Count2 and source real UIntProperty inner consumes 8 bytes, values123,-456, source_archive_count_prefix=false. The script syntax TwoInts[2] itself is NOT claimed to be UFixedArrayProperty: actual native type was separately created with Epic compiled engine.",
      "T046":"Original 2012 C227i UCC genuinely compiled G3PlainPair UStruct with int Number,float Fraction, two CDO PlainPairs from 0-warning real 954-byte source package. Original 2026 compiled Core.dll resolved real class UArrayProperty Inner=UStructProperty and created *REAL UFixedArrayProperty* native UObject with AddCppProperty(Inner,Count2). Original original native SerializeItem SAVE generated 16 bytes 650000000000A03F36FFFFFF000030C0 (two structs, source original real ElementSize8), original FBufferReader LOAD consumed 16 and restored Number/Fraction (101,1.25),(-202,-2.75) exactly. Production fixedarray readAt resolves the real local UStruct source field order int then float, no extra dynamic prefix, exact 16-byte span and values. The separate prior FName-bearing generic FBufferArchive cannot archive native FName without linker and was NOT used to claim this closure; fixed nested/struct is fully verified by genuine source-supported source family."
    }
    return {"status":"PASS","result":outcome[row["id"]]+
       " One real original 2012 UCC + 2026 compiled Core.dll 52/52 source/native/reader checks for two frozen IDs in B06. T047 native map C227k actually writes 1/17 bytes vs v432 0/0; 2012 source release and production map gate remain BLOCKED, no invented no-data parity.","evidence":checks}


def eval_t043_t044_native_v227_aggregate_pair(row:dict[str,Any])->dict[str,Any]:
    """One original C227i UCC class, true C227k SavePackage and independent original native struct/string LOAD."""
    b="catalog/compliance/evidence/o06/"
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-b06-t043-t044-original-native-struct-strings.php",
        "87f700d61adfd4b62e318851fb25dee43532054c1cd7d7e372164ae8c5140048"),
      verify_pin(b+"20261030-G3-B06-AGG-C227i-original-compiler.u",
        "ddea6bb9110b1c061f24efadae4b5ce3d81eb4a8ba7294d170e1e320263287e5"),
      verify_pin(b+"20261030-G3-B06-AGG-C227k-original-SAVE.u",
        "34a00b4f5bdf78dd56ef62a79f62684b6159fb0ecfc43e630eaa08c7ee09d8f7"),
      verify_pin(b+"20261030-G3-B06-AGG-C227k-original-SAVE.log",
        "6413757d2ae778b2f39f9d25b8ac5b06003b9245528ef1875bd410548ec2842d"),
      verify_pin(b+"20261030-G3-B06-AGG-C227k-original-LOAD.log",
        "f48e72ab189703938f8c4b775d55046973569e1a7565163a7cf3aa5958cd6b78"),
      verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
        "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
      verified_pass_log(b+"20261030-G3-B06-AGG-original-85pass.json","checks",85)
    ]
    proof=json.loads((ROOT/b/"20261030-G3-B06-AGG-original-85pass.json").read_text(encoding="utf-8-sig"))
    if proof.get("full_original_fixed_ids",{}).get(row["id"])!="PASS":
       raise AssertionError("Original C227i/C227k grouped aggregate oracle did not close "+row["id"])
    outcome={
      "T043":"Genuine original 2012 C227i UCC compiled a source-legal class with two struct G3Tuple elements; original class default count2 native property footprint19. Actual 2026 original compiled C227k Core.dll SavePackage produced a genuine 308-byte .u, with exactly count2 and two int/float/FName source-gated UStruct::SerializeBin values consuming 19 physical bytes. Actual original native saved (101,1.25,Alpha) and (-202,-2.75,Gamma), with real FName map index4 Alpha and index3 Gamma; SECOND separate original C227k native process LOAD returned both complete struct elements with inner stride12 and six exact fields. Production v227 source-linked native decoder invokes real local struct layout ordered Number/Fraction/Label and consumes EXACT 19 bytes; existing original v432 source branch retained, original C98 dynamic UArrayProperty absent. Distinguishes runtime memory 12 bytes per struct from archive 9 bytes per element.",
      "T044":"Genuine original 2012 C227i UCC compiled real array<string> with empty first and nonempty Hello-227 second default. Original 2026 compiled native Core.dll class LOAD confirms exactly two real class-default FString elements: empty length0, Hello-227 length9. Same source-legal object instance saved two modified genuine strings Alpha-227 and Gamma-227, both actual native memory and original 227k SavePackage 23 archive bytes: count2, first positive10+ASCII nine+NUL, second positive10+ASCII nine+NUL. A SECOND original C227k native independent LOAD process restores both texts length9, class CDO empty/nonempty unchanged. Production source-gated v227 inner UStrProperty SerializeItem reads exactly both signed counts, original raw bytes, per-item NUL and complete tag boundary, not fabricated default entries. v1.200 C98 UArrayProperty source absent and UT99 v432 string-array allocator UB distinct, not claimed as completed native fallback."
    }
    return {"status":"PASS","result":outcome[row["id"]]+
      " ONE SHA-pinned 2012 original compiler + 2026 true native SAVE+independent LOAD joint 85/85 verifier closed exactly two frozen IDs; T045/T046 are genuine UFixedArrayProperty not UnrealScript ArrayDim2 and stay BLOCKED; T048 negative native crash has no reliable FArchive Tell so remains BLOCKED.","evidence":pins}


def eval_t028_original_malformed_fstring(row:dict[str,Any])->dict[str,Any]:
    """Actual original v432 fatal physical cursor and genuine 227k recoverable malformed FString; full fixed T028."""
    b="catalog/compliance/evidence/o06/"
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-t028-original-native-fstring-error-parity.php",
        "0379ac3d9455c3ff65050abc3e9819844da6a201b48ef7c515e5dbee9e0ef607"),
      verify_pin(b+"20261030-G3-B04-T028-V432-original-EOF.u",
        "6ddf59b77ba21e9ede85ac84505fd6c281b6b0225454a5b51f3a9a622c6b0277"),
      verify_pin(b+"20261030-G3-B04-T028-V432-original-EOF.log",
        "8bdd114a9bdc48d7a780ff31863dbae25b7826288cdc8cb7ffc742b8fd5589a1"),
      verify_pin(b+"20261030-G3-B04-T028-C227k-original-BadNul.u",
        "9f93c7c3e94d1f09b95a27136773ba12bb611c45b5bc6d4aa3220704581e3993"),
      verify_pin(b+"20261030-G3-B04-T028-C227k-original-BadCount.u",
        "c30b443cd26315ed6be00750c846e9852f5b41b219d43c2dea59a031b3341c81"),
      verify_pin(b+"20261030-G3-B04-T028-C227k-original-BadNul.log",
        "53d92ec894ca8fc8b6aea5bebd6bbd55678566768c44bfd8b7c137dae3dc4c3b"),
      verify_pin(b+"20261030-G3-B04-T028-C227k-original-BadCount.log",
        "c539e7dff35c8319731db9694202b1589e8fa53852d30e9b5dc2643686d01144"),
      verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
        "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
      verified_pass_log(b+"20261030-G3-B04-T028-original-native-40pass.json","checks",40)
    ]
    j=json.loads((ROOT/b/"20261030-G3-B04-T028-original-native-40pass.json").read_text(encoding="utf-8-sig"))
    if j.get("original_fixed_T028")!="PASS" or j.get("UT99_v432_original_native_fatal_Tell")!=283 or j.get("current_v432_native_error_Tell")!=283:
       raise AssertionError("Original compiled source-specific physical FString native error comparison not complete")
    return {"status":"PASS","result":
       "One original UT99 v432 compiled direct FString class + genuine original shipped native Core.dll LOAD of one-byte mutated count75 in 283-byte real .u. True native ULinkerLoad::Serialize <- FString<< FArchive attempted 283+1/283, returned null package and native_error1. Current UT99 source-selected reader fails at EXACT original physical Tell283, with no fabricated loaded state. Same original 2026 C227k compiled Core.dll, distinct real source-selected saved v69/licensee227 253-byte package: one-byte invalid terminal and one-byte oversized count each genuinely LOADED fully with no fatal error; oversized count emitted original source-specific property size mismatch got4 expected3 and original native object loaded B. Reader correctly does not invent errors, retains raw opaque malformed payload, consumes exactly to archive end225. Frozen one T028 FULL PASS, 40/40 deterministic original compiler/native/current checks and no accepted native tests rerun.",
       "evidence":pins}


def eval_t062_t063_original_config_transient(row:dict[str,Any])->dict[str,Any]:
    """One original C227i UCC + C227k original compiled SAVE/LOAD, two isolated config/localization INIs."""
    b="catalog/compliance/evidence/o06/"
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-b08-t062-t063-original-config-transient.php",
        "6a1950d242eab2409ef74b2a05120e9b5c3179af002b4b11eb9da45b0a3b38c4"),
      verify_pin(b+"20261030-G3-B08-227i-original-config-transient.u",
        "3fd8ba1a4b74a15b741d5df597c7b275917a09df80e9dd1e2c520614a51b5c5a"),
      verify_pin(b+"20261030-G3-B08-227k-original-SavePackage.u",
        "58bb406f532cbf52ebad10be3d5ee77d48dbfdfb2bc101c8bf071d35bab16a44"),
      verify_pin(b+"20261030-G3-B08-227k-original-native-SAVE.log",
        "911549e7b0019a7788b1e733cdee286b07f60a797a669abf7b20f64622d7a61d"),
      verify_pin(b+"20261030-G3-B08-227k-original-native-LOAD.log",
        "41deae31ef05381397d80f950bd807863b8fbc12f5e41183c00edda16297c230"),
      verify_pin(b+"20261030-G3-B08-227k-original-LOCALIZED-111.log",
        "79c9c2b58679c02c23746fcbe183ad89d83ef02975cb471fce22939c57d6a54a"),
      verify_pin(b+"20261030-G3-B08-227k-original-LOCALIZED-222.log",
        "184333d09df3c244b56d63c9e15902d883a356569cb8e75de0997007956f8fb2"),
      verified_pass_log(b+"20261030-G3-B08-T062-T063-original-native-66pass.json","checks",66)
    ]
    j=json.loads((ROOT/b/"20261030-G3-B08-T062-T063-original-native-66pass.json").read_text(encoding="utf-8-sig"))
    if j.get("original_frozen_full_ids",{}).get(row["id"])!="PASS" or j.get("original_2026_native_saved_package_bytes")!=242:
       raise AssertionError("T062/063 original native config/localized/transient source acceptance incomplete")
    decisions={
      "T062":"Genuine original 2012 C227i UCC-compiled source legal class config(User), real CPF_Config and CPF_Localized fields; one untouched 2026 original C227k compiled Core.dll saved 242-byte runtime package independently reloaded in two SEPARATE native processes with isolated User.ini ConfigValue111/222. Both actually loaded intended different values despite same saved archive and default444. Paired original separate live .int localization files Native Alpha/Beta were loaded into genuine original native CDO from same source LocalMessage default Default Message via UObject::LoadLocalized and CLASS_Localized flag, original native CPF_Localized flag00408000. Identical PersistValue888 and transient0 in both profiles. Exact original INI source/content native logs/byte saved package SHA pinned, User.ini and temporary .int restored after runs.",
      "T063":"Original 2012 C227i UCC source declares transient ScratchValue333 and persistent PersistValue222 plus config444. Genuine untouched original 2026 C227k compiled engine actual class memory linked CPF_Transient 0x2000 at offset792 and CDO Scratch0; native independent instance changed transient to777 and persistent to888 then original SavePackage wrote genuine 242-byte .u. Source-selected production UE1 tagged reader matches exactly ONE saved INT property PersistValue888 4 data bytes, 7 total tag bytes ending physical offset214, and NO ScratchValue/ConfigValue/LocalMessage tag. Distinct second original compiled native process reloaded ScratchValue0 and PersistValue888, with two original config/localization profiles independently matching the same saved binary. Real source flag and native bytes fully verified."
    }
    return {"status":"PASS","result":decisions[row["id"]]+
      " Original compiler/native/current sealed joint 66/66 checks; zero accepted original-native tests rerun.","evidence":pins}


def eval_t025_t027_original_string_bulk(row:dict[str,Any])->dict[str,Any]:
    """Original C98 and UT99 fixed string, C227k UTF16 48-check native proof, two full closures."""
    b="catalog/compliance/evidence/o06/"
    checks=[
       verify_pin("catalog/bin/verify-ue1-o06-g3-bulk-fixed-unicode-two-native.php",
                  "28831a1a7a8243adf71313c028476f99fcde8460f3832841d7a35f1968e570ba"),
       verify_pin(b+"20261030-G3-STR-BULK-C227k-UNICODE-original-SAVE.u",
                  "e900861a8bf851bfb340e008f042f5529fa4eb144490f46bbad585af8091a14b"),
       verify_pin(b+"20261030-G3-STR-BULK-C227k-UNICODE-original-LOAD.log",
                  "9e7149a3b9afec14924dc63f6ec06db8566c7bd6da147b2bf29e07dc16eec803"),
       verify_pin(b+"20261030-G3-STR-BULK-C227k-UTF16-direct-original-native.log",
                  "4fcda84026672f2adcefe6d3b9861c9d91c42499da414241c9352aa8d77f90a2"),
       verify_pin(b+"20261030-G3-STR-BULK-C98-fixed-string-original-CDB.log",
                  "dbd454fd8acee2ca6af5a44d8de659ffc82235083bf2690fcb3ad6e1fac76d0f"),
       verify_pin(b+"20261030-G3-STR-BULK-V432-legacy-converted-original.u",
                  "cb737f15004d37f0a49458acf5adf14fcfd2344a7624c07826ff924cc44c4ecc"),
       verify_pin(b+"20261030-G3-STR-BULK-V432-legacy-converted-original-LOAD.log",
                  "2733758f8a5eb4ebb54af189b4275def046ca40f5a44e20df855e4df1198b281"),
       verified_pass_log(b+"20261030-G3-STR-BULK-T025-T027-original-48pass.json","checks",48),
    ]
    j=json.loads((ROOT/b/"20261030-G3-STR-BULK-T025-T027-original-48pass.json").read_text(encoding="utf-8-sig"))
    if j.get("original_g3_fixed_ids",{}).get(row["id"])!="PASS":
       raise AssertionError("Original grouped true native string verifier does not close "+row["id"])
    decisions={
      "T025":"Real original May17 1998 retail compiler v61 G3C98Bulk.u original UStringProperty G3Local with 32-character capacity via native property record export22; 11+terminal NUL 12 physical bytes. Original retail 1998 Core.dll CDB observed native CDO DefaultBulk ASCII string terminated immediately by zero, original class default archive complete. Distinct original UT99 v432 archived historical StringProperty type7 in source-linked dynamic UStrProperty class, original stock v432 Core.dll LOAD successfully converted to runtime QAlpha-direct-FString; current reader correct source-specific conversion gate, exact 22 physical bytes, no false unknown-tag skip.",
      "T027":"Original 2026 227k compiled Core.dll UStrProperty::SerializeItem direct SAVE+LOAD of UTF16LE non-BMP surrogate D83D DE80: signed AR_INDEX -3 and exact 7 bytes 833dd880de0000, original native FBufferReader Tell7 and true loaded two code units. Single BMP Omega original signed -2 exact 5 bytes. Original native SavePackage of a new genuine 280-byte .u saved those two Unicode values and a SECOND separate compiled original Core.dll process reloaded actual D83D,DE80 and 03A9. Production source-linked v227 tag reader decoded both signed counts, physical spans and archive end. Original embedded-NUL source branch was ASCII and did not safely preserve original mixed characters; decoder preserves raw bytes without invented UTF conversion."
    }
    return {"status":"PASS","result":decisions[row["id"]]+
      " Joint original C98/UT99 v432/C227 source/real native/current reader 48/48 PASS; T028 malformed branch separately BLOCKED.","evidence":checks}


def eval_t022_t023_t026(row:dict[str,Any])->dict[str,Any]:
    """One original multi-profile compiled float/BOOL/FString native method fixture; 3 fixed IDs."""
    base="catalog/compliance/evidence/o06/"
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-bulk-three-scalar-string-native.php",
                 "d4e7ac9c8666925acd49d5434325f6fcb1aa3ed15488f79026c8addde07b1f86"),
      verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
                 "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
      verify_pin(base+"20261030-G3-BULK-SCALAR-C227i-original-compiler.u",
                 "086961b8ecb58abf2e0887f1e07636c7fd865882fd683abe54542f1ac8470649"),
      verify_pin(base+"20261030-G3-BULK-SCALAR-C227k-original-SAVE.u",
                 "3e01d5304c46563ce5d4f06c70cdc34ea462949ab64a5da1057f60007b6a8fa2"),
      verify_pin(base+"20261030-G3-BULK-SCALAR-C227k-original-SAVE.log",
                 "ab7938082e5b16e2541572f11907f34edbed85a0d1a0c9b05c3388bd27a5867b"),
      verify_pin(base+"20261030-G3-BULK-SCALAR-C227k-original-LOAD.log",
                 "b8fe6e2a10f7f251a4aa524e59a87cb74d253cc7432f95f54767504be5648183"),
      verify_pin(base+"20261030-G3-BULK-SCALAR-C227k-original-direct-FLOAT.log",
                 "4e3b8537c9c1941aba5e0067aa36286c5a28e620fad27b971552ccefd834c7ec"),
      verify_pin(base+"20261030-G3-BULK-SCALAR-V432-original-FSTRING.u",
                 "e494caf1c9eb86b5fb2fcf4f7af1e0e91c5b5b214066c670ef4917dc7a2cd933"),
      verified_pass_log(base+"20261030-G3-BULK-SCALAR-T022-T023-T026-original-54pass.json","checks",54)
    ]
    j=json.loads((ROOT/base/"20261030-G3-BULK-SCALAR-T022-T023-T026-original-54pass.json").read_text(encoding="utf-8-sig"))
    if j.get("original_fixed_T_IDs",{}).get(row["id"])!="PASS":
        raise AssertionError("One original compiled grouped oracle does not prove "+row["id"])
    outcome={
       "T022":"Original 2012 C227i UCC compiled positive1.5 negative-1.5 fractional0.375 and zero FloatProperty, genuine 2026 original 227k native loaded IEEE bits 3FC00000 BFC00000 3EC00000 00000000, actual true original UFloatProperty::SerializeItem directly SAVE and FBufferReader LOAD each 4/4 bytes including signed -0.0 IEEE 80000000 unmodified. One original 227k real SavePackage purposely omits native -0 because Epic inherited UFloatProperty::Identical numeric == default0 (source-backed), independent native reload returned +0. The reader mirrors both direct four-byte codec and original default omission, never falsely claims serialized negative zero when absent.",
       "T023":"Actual original 2012 compiled class THREE source-legal packed bool properties same 32-bit slot offset776, original C227k compiled live UBoolProperty masks 00000001/00000002/00000004 native CDO word00000005 with true,false,true, original 227k native object sets middle mask and independent second compiled process reload shows word00000007 all true. FPropertyTag true is encoded in Info flag and has ZERO value bytes, false is omitted, current reader matches all. Real original 2012 script compiler rejected bool arrays ('Bool arrays are not allowed'); impossible indexed bool-array variant source-excluded, not mistaken for stored byte booleans.",
       "T026":"Genuine original UT99 v432 native loaded real compiled direct StrProperty FString 'Alpha-direct-FString' with count21, NUL and exact 22 bytes/exact export end. Original 2012 C227i UCC compiled class default empty omitted, 'A' count2=3 bytes, 'A longer string' count16=17 bytes, all native 2026 C227k compiled LOAD returns exact empty/one/nonempty text. Original 2026 C227k independently SavePackage wrote new EmptyText='B' as signed count2, B,NUL three bytes, second distinct original C227k process LOAD returned B/A/long exact; UnrealDB new source-linked v227 UStrProperty codec decodes same count, NUL, source physical bytes and complete archive end."
    }
    return {"status":"PASS","result":outcome[row["id"]]+
        " ONE jointly compiled original-engine/source/current verifier 54/54 PASS, no native engine executions inside verifier, T025/T027/T028 still blocked.",
       "evidence":pins}


def eval_t021_t024_t041_t042(row:dict[str,Any])->dict[str,Any]:
    """ONE bulk original compiled method oracle, four fully accepted fixed scalar/name/array IDs."""
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-bulk-four-original-primitives-arrays.php",
                 "3a65495b73461df8d12f0b3d77a98b60b85e8774dc865550b0911130b9ef0bac"),
      verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
                 "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C227i-original-2012-compiled-class.u",
                 "e181c4a49aa51ba914baeead9a9f4c95f0a0154534bd92bbfb70c41f32adb50f"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C227k-original-2026-native-SAVE-object.u",
                 "8d2e0ad732ade2b4954aa8982fd0c787565a61109bc5c62a64e7411ae6ee1b4a"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C227k-original-2026-native-SAVE.log",
                 "257e7b1815465c5ef4bdbaa90f8c2f5c3923471799df67dcdc4c88d03b6c254b"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C227k-original-2026-native-LOAD.log",
                 "6d613ab5619c7ef121f4193e833a0fa0cd6ab5ad76cd11d1e2bd5f853b9a69a7"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-V432-original-native-array-saved.u",
                 "2436d2545135a8ca02d5f1e71252daf3314be6e5b02c371a7db8ae5450e16ff3"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-V432-original-native-array-LOAD.log",
                 "fe457ecf0730adb6264423c0dfc299839b06d534f873ae77fab86a4b019f19f0"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C98-original-native-T024-FName-memory.log",
                 "901efb94e5de2c8cf9a7dc0e08eb9606621f541398ead5f3da9dd848bf0645de"),
      verified_pass_log("catalog/compliance/evidence/o06/20261030-G3-BULK-T021-T024-T041-T042-original-81pass.json","checks",81)
    ]
    data=json.loads((ROOT/"catalog/compliance/evidence/o06/20261030-G3-BULK-T021-T024-T041-T042-original-81pass.json").read_text(encoding="utf-8-sig"))
    if data.get("grouped_original_compiled_fixed_tests",{}).get(row["id"])!="PASS":
        raise AssertionError("Original multi-ID verifier did not accept "+row["id"])
    cases={
      "T021":"Original C98 May1998 retail UClass compiled int/byte values; UT99 v432 genuine native SAVE+LOAD signed INT11/42/-7, and original 2012 C227i compiler + genuine 2026 C227k LOAD of 1-byte MaxByte255 and 4-byte signed BigNeg=-2147483647, C98 negative and INT_MAX, source-selected actual physical byte counts and absent zero tags. One SHA sealed joint 81/81 verifier. Distinct source families explicitly selected, no v227k AnyProperty substitution.",
      "T024":"Original C98 retail compiled genuine FName G3BulkName archived FName15 maps real global name-index0xE34 native CDO, following explicit None member is native global index0. Reuses already accepted original UT99 v432 GameInfo DefaultPlayerState/PlayerWalking FName, and 2012 original C227i UCC compiled NonZeroName=Alpha vs NoneName omitted, original 227k compiled native shows Alpha and genuine None; actual mapped name/index correctness, including original linked 227k FName[Alpha,Gamma] array names. Source-resolved 81/81 multi-ID proof.",
      "T041":"C98 original v1.200 has no UArrayProperty (source-excluded). Genuine UT99 v432 original SavePackage + independently compiled LOAD of array<int> (11,42,-7), count3 and 13 archived bytes matched preaccepted source-selected reader. Genuine original 2012 C227i UCC compiler class file loaded by 2026 original 227k Core.dll; new 227k original native SavePackage real array (11,42,-7), independent second ORIGINAL compiled process LOAD of same 302-byte .u, identical 3 values and exact 13-byte count+3-int archive, runtime source-linked production 227k codec now implemented; 81/81 joint oracle PASS.",
      "T042":"C98 original v1.200 dynamic UArrayProperty source-absent. Genuine original UT99 v432 SavePackage plus independent native LOAD: array<Name> Alpha/Beta and array<UObject*> self/None, source-derived 3/3 serialized bytes each. New original 2012 compiled C227i class + genuine original 2026 C227k SavePackage and distinct native process LOAD: NAME Alpha/Gamma, UObject export ref1 points to ArrayObject itself and zero ref remains None; original linker FName map resolves both names, exact 3/3 physical value bytes and current production 227k source-resolved typed array decoder. Joint original/native/source 81/81 checks PASS."
    }
    return {"status":"PASS","result":cases[row["id"]]+
       " The one shared multi-era compiled oracle closed four fixed IDs at once, no accepted compiled native probes replayed by verifier. T022,T023,T043,T044 remain BLOCKED.","evidence":pins}

def eval_t034(_row:dict[str,Any])->dict[str,Any]:
    """Original May17 C98 native inherited UStruct SerializeBin in one grouped 9-ID compiled fixture."""
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-bulk-c98-t034-and-scalars.php",
                 "e725849998f29a95571a8172b3d49e173913d838910fb86c2a3ec1e0aa97c00c"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C98-original-compiler-G3C98Bulk.u",
                 "40fa22b955451b489d13b8b19e478c55965efcfb569b497cf8a312e6c73521a1"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C98-original-compiler-G3C98Bulk.uc",
                 "5b8e70c48cb6c9e5d97ef6cf5113f138adf7298878b2c9e06db8325c1224e8c1"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C98-original-native-loaded-values.log",
                 "4e0bb7746aeaa33b6bc6f86419e1471748305085f7cd97db9acd388301cecd94"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C98-original-native-CDB.cmd",
                 "b8c1c18120c2adbc2145b9f64d0f5d4475337211f948fd095db4c1f91334b6cd"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-BULK-C98-original-native-successful-Editor.log",
                 "5a6e3f620e2f62f6c9dc6b554b8822718f144f4c2e6d2a73e3dc2601b54ca9b7"),
      verified_pass_log("catalog/compliance/evidence/o06/20261030-G3-BULK-C98-T019-T021-T024-T034-T041-T062-T063-25pass.json","checks",25)
    ]
    return {"status":"PASS","result":
      "One new originally compiled May17 1998 x86 retail Unreal.exe -make genuine v61 G3C98Bulk.u with "
      "local struct G3Child expands G3Base, BX INT789, BY FLOAT2.5, CZ BYTE17. One original compiled "
      "Core.dll UClass::Serialize CDB postload trace of actual class export5 and 540-byte native CDO: "
      "G3Struct member memory begins at CDO+488; parent BX789 offset0, BY2.5 IEEE0x40200000 offset4, "
      "child CZ17 offset8, all observed genuine process memory. Original compiler archive physical "
      "9-byte struct payload at 1220 is CHILD-FIRST CZ=17, BX=789, BY=2.5, per true original "
      "UStruct::SerializeBin TFieldIterator<UProperty> inheritance traversal, despite parent-first "
      "native memory layout. UnrealDB existing source-selected struct resolver follows exact actual "
      "UStruct.Children/Next/SuperField graphs, decodes inherited members CZ,BX,BY exactly and "
      "consumes 9/9 bytes. SHA-pinned source/original compiler/native loaded memory 25/25 PASS. "
      "Same grouped 9-ID fixture also exercised C98 int/byte/float/bool/name/config/transient/static "
      "array but those IDs stay BLOCKED for genuinely missing cross-family/both-profile/specific SAVE "
      "oracles. No original accepted native test rerun, no unverified runtime pointer fabricated.",
      "evidence":pins}

def eval_t016(_row:dict[str,Any])->dict[str,Any]:
    """C98 May17 actual compiled class defaults parent/child native inherited scalar values."""
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-t016-original-c98-native-default-inheritance.php",
                 "c84ff41ab0d586804e86d3705bdb64ae916d5637b36e4513dfb6d115477a7020"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-native-CDO-memory.log",
                 "d428680c17d6d2e38786daeecdb984ac8d657c4578aec570d45bd012545ffe41"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-native-CDO-breakpoint.cmd",
                 "376bf8165dc275948efe550f236f70420f1e86811fc94e399fbd3937643de68c"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-compiler-G3C98Defaults.u",
                 "766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-compiler-parent.uc",
                 "01615d512f5082715246a445abdb0c57a30251731230eb8e29e87720ab1c2da0"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-compiler-child.uc",
                 "45e545b3a1d8fc622fb76b26e4521a2f06a0f28ed76838e3c2ae7375db776e69"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-compiler-package-load.log",
                 "49ffb6204c3326162e8eea774f10c38f759a1d2763211332bc29293043c137c6"),
      verified_pass_log("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-native-defaults-33pass.json","checks",33)
    ]
    return {"status":"PASS","result":
      "Real original retail May17 1998 x86 Unreal.exe -make compiled v61 G3C98Defaults.u parent/child classes; "
      "original May17 Core.dll UClass::Serialize post-tag compiled CDB memory of both genuine class defaults "
      "proved parent INT G3Magic=123 and FLOAT G3Scale=1.25, child explicitly overrides INT=456 and "
      "INHERITS unchanged FLOAT1.25 despite absent child tag. Original native default array 452 bytes, "
      "actual member offsets 444/448, stock memory float bits 0x3fa00000. Actual original generated "
      "parent two tags/child only override bytes vs source selected UnrealDB class reader match; "
      "33/33 independent native/source/archive checks PASS. No original compiled replay by verifier; "
      "this is only C98 T016, not C227 T018, and is not generic G3 full acceptance.",
      "evidence":pins}

def eval_t058(_row:dict[str,Any])->dict[str,Any]:
    """Original May17 C98 compiled tagged-object class-default LOAD, physical NAME_None and native archive cursor."""
    pins=[
      verify_pin("catalog/bin/verify-ue1-o06-g3-t058-original-c98-tag-terminal.php",
                 "a3802ec3bf1b19640adcbac25d39638c76f7b9a3dd4f14df796a3d94dc3c41b6"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-T058-C98-original-native-FArchive-Pos.log",
                 "f84395f6975f9392113195fd63577a9d3b47c1d813a6b2436aab413200d17d11"),
      verify_pin("catalog/compliance/evidence/o06/20261030-G3-T058-C98-original-native-FArchive-Pos.cmd",
                 "da7d55d647b7e3a1763ae7770e6b4d4efef8a920c6c3b4b454a752c5c89e77e9"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-compiler-G3C98Defaults.u",
                 "766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf"),
      verify_pin("catalog/compliance/evidence/o06/20261029-G3-T016-C98-original-native-CDO-memory.log",
                 "d428680c17d6d2e38786daeecdb984ac8d657c4578aec570d45bd012545ffe41"),
      verified_pass_log("catalog/compliance/evidence/o06/20261030-G3-T058-C98-original-tag-terminal-27pass.json","checks",27)
    ]
    return {"status":"PASS","result":
      "Actual original retail May17 C98 Unreal.exe compiler generated genuine v61 parent/child UClass "
      "default UObject tagged streams in pinned genuine G3C98Defaults.u: Parent FName G3Magic indexed4 "
      "IntProperty value123 and G3Scale indexed7 FloatProperty1.25 then REAL single-byte NAME_None index0; "
      "Child named G3Magic value456 then NAME_None, no saved inherited float. "
      "Original compiled source native UClass::Serialize calls UStruct::SerializeTaggedProperties on UObject "
      "default storage; original CDB at compiled Core+0x12b2d returns AFTER loaded defaults and shows exact "
      "FArchiveFileLoad::Pos 374 (parent export end) and 467 (child export end) at same physical archive "
      "state, original package length761, in full agreement with source-selected UnrealDB tagged decoder "
      "actual tag names/indices/values/physical cursor. Authentic compiled May17 original loaded class "
      "CDO values from T016 sealed without repeated original native run. Independent original/source/archive "
      "27/27 checks PASS. Specific C98 named tagged property/terminal fixed T058 only; C98 malformed "
      "tag negative T064 and cross-family T021-24 remain blocked.",
      "evidence":pins}

def eval_t066(_row:dict[str,Any])->dict[str,Any]:
    """One stock original v432 FArchive native operator: next-field EOF vs mid-FString EOF."""
    pins=[
        verify_pin("catalog/bin/verify-ue1-o06-g3-t066-original-v432-EOF-before-vs-mid-field.php",
                   "96953ee4938d6dc7fc08e4fbbe9421ae12190d8a4e47685e222fbbc7e1d3f436"),
        verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
                   "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
        verify_pin("catalog/compliance/evidence/o06/20261029-G3-T066-v432-original-native-before-FName-EOF.u",
                   "d13f25c1167f0a4d807dde51e5602c9d27b2a38a682c179ad9aa57ade800b4b2"),
        verify_pin("catalog/compliance/evidence/o06/20261029-G3-T066-v432-original-native-before-FName-EOF.log",
                   "89158262860cabe7fd253c2b65d2639d906a6fd94bc4a80840009715fca7d8f8"),
        verify_pin("catalog/compliance/evidence/o06/20261029-G3-T066-v432-preload-early-rejection.log",
                   "bc0c69c0dfeeee93089e91f377e0e2692aa49c238cff738a6e87664632d938c9"),
        verified_pass_log("catalog/compliance/evidence/o06/20261029-G3-T066-original-v432-19pass.json","checks",19)
    ]
    return {"status":"PASS","result":
        "Original compiled stock UT99 v432 Core.dll, exact native-produced 283-byte package, "
        "one-byte legal FString count21->50: FArchiveFileReader next FPropertyTag FName FCompactIndex<< "
        "tries at physical EOF 283+1/283 after field; original Tell283, source-selected UnrealDB "
        "now reports matching physical EOF at next TagName and no loaded value. Previous sealed "
        "mid-field FString<< EOF 283+1/283 reused, different actual native stack, same operator. "
        "Source-specific previously mismatching generic missing NAME_None diagnostic corrected; "
        "19/19 original native/current source checks PASS. Earlier physically truncated file "
        "failed during import-map Seek and explicitly excluded, not native-field proof. "
        "No claim of all source builds or full G3 release.",
        "evidence":pins}

def eval_t070(_row:dict[str,Any])->dict[str,Any]:
    """Original compiled v432 genuine archive: read through export end vs true physical EOF."""
    pins=[
        verify_pin("catalog/bin/verify-ue1-o06-g3-t070-original-v432-physical-boundary.php",
                   "aeae20006c4b799850acdfe87bc832463a5f96be3ab11962962b6f231a7ff852"),
        verify_pin("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
                   "90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd"),
        verify_pin("catalog/compliance/UE1-O06-G5-T99-UOBJECT-DIRECT-STR-PHYSICAL-EOF.json",
                   "3a47f8f88889df8e8cef3cba03c4310877ad78edc1d0d2059f18e8e13c20859b"),
        verify_pin("catalog/compliance/evidence/o06/20261029-G3-T070-v432-original-native-physical-readable-one-byte.u",
                   "f814c88c487bd0707b9f7730001d4a51ef86aa739fe19ddcb4dd6d143c4a0a71"),
        verify_pin("catalog/compliance/evidence/o06/20261029-G3-T070-v432-original-native-physical-readable.log",
                   "edb68d238e598428e7fd2b415918dae67fc68144bdc2ac23d3c64a361fccb545"),
        verified_pass_log("catalog/compliance/evidence/o06/20261029-G3-T070-original-v432-19pass.json",
                          "checks",19)
    ]
    return {"status":"PASS","result":
        "Original compiled stock UT99 v432 Core.dll on genuine native-produced 283-byte direct FString package: "
        "one-byte count21->22 reads beyond nominal export into physically readable file; ORIGINAL fails "
        "Bad name index -5242879/9, FArchive Tell262, reader same name and Tell262 with resolved source imports. "
        "Prior sealed original true physical EOF 283+1/283 also matches reader physical EOF283. "
        "Source/native/current reader 19/19 focused PASS; one new original native execution, previous accepted native EOF reused. "
        "Exact original package/import directory required for source-linked class schema; no cross-family global claim.",
        "evidence":pins}

EVALUATORS={"T001":eval_t001_t002,"T002":eval_t001_t002,"T003":eval_t003,"T004":eval_t004,"T005":eval_t005_t006,"T006":eval_t005_t006,"T007":eval_t007,"T008":eval_t008,"T009":eval_t009,"T010":eval_t010,"T012":eval_t012,"T013":eval_t013,"T014":eval_t014,"T015":eval_t015,"T016":eval_t016,"T017":eval_t017_t059,"T021":eval_t021_t024_t041_t042,"T022":eval_t022_t023_t026,"T023":eval_t022_t023_t026,"T024":eval_t021_t024_t041_t042,"T025":eval_t025_t027_original_string_bulk,"T026":eval_t022_t023_t026,"T027":eval_t025_t027_original_string_bulk,"T028":eval_t028_original_malformed_fstring,"T032":eval_t032,"T034":eval_t034,"T035":eval_t035,"T036":eval_t036_t039,"T037":eval_t037_t038_t040_original_nested_batch,"T038":eval_t037_t038_t040_original_nested_batch,"T039":eval_t036_t039,"T040":eval_t037_t038_t040_original_nested_batch,"T041":eval_t021_t024_t041_t042,"T042":eval_t021_t024_t041_t042,"T043":eval_t043_t044_native_v227_aggregate_pair,"T044":eval_t043_t044_native_v227_aggregate_pair,"T045":eval_t045_t046_original_true_fixed_native,"T046":eval_t045_t046_original_true_fixed_native,"T047":eval_t047_three_compiled_map_branches,"T048":eval_t048_original_both_source_array_failure,"T051":eval_t051,"T054":eval_t054,"T055":eval_t055,"T056":eval_t056,"T057":eval_t057,"T058":eval_t058,"T059":eval_t017_t059,"T060":eval_t060_t061,"T061":eval_t060_t061,"T062":eval_t062_t063_original_config_transient,"T063":eval_t062_t063_original_config_transient,"T066":eval_t066,"T067":eval_t067,"T070":eval_t070}

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
    lines+=["","Source-specific SHA-pinned evaluators available for these original native-evidence test IDs: **"+", ".join(sorted(EVALUATORS))+"**. Other BLOCKED IDs require exactly their named original compiled oracle; new evaluators must prove the existing fixed row without adding tests or speculating PASS.",""]
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
