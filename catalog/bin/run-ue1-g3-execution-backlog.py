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
         "f7551d2c8bc8b114e76ee6041d1bb962b761bca43c1eabc3f4632e593b40c709"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php",
         "0cfba36bbe3b36a80de885d5d6c3e22f37ab8a5b14079dab65285a86c2752128"),
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
        (script,"1ade9c0d9a18171908b97d26cb1a58a22fb786694bd0512c9c1953413a651953"),
        (result_path,"ec6c78721ed173a023e4d6dd58d84be022f80abd8ff5035b7eb7127b4cd22e45"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php",
         "2b87b97b2c6c0d830205623cce6cf2594792952ef6ebf5314e88f0a2511f27b1"),
        ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php",
         "f7551d2c8bc8b114e76ee6041d1bb962b761bca43c1eabc3f4632e593b40c709"),
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
      ("catalog/bin/verify-ue1-o06-g3-t036-t039-original-c227k-nested-native.php","b4224496d7a938e30a37825b27fa02b58ae074fc91a25ac52a87c850567bce68"),
      (b+"20261029-G3-T036-T039-original-C227k-95pass.json","506bb85d67b408283f8018235a1880bd45f8291ef31c018d6a786ec6f66a16db"),
      (b+"20261029-G3-T036-T039-C227k-original-nested-struct-native.log","edce7870d4a3522ed053bfe1a5b115e345ac4fd6ba87ffdbd40fdb5bfb1849da"),
      (b+"20261029-G3-T036-T039-C227k-original-nested-struct-probe.cpp","59dfc53678899142459a1511cb06f332a545ffaa436592cdf16cab8301c87e9e"),
      (b+"20261029-G3-T060-T061-original-C227k-six-CDO-902pass.json","760a82b677c59fcc476b732b374756e26ec07a14d97727a7a817b7c47f4d6a66"),
      (b+"20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log","f78d3d518a01952667a3ca51698bf683cfc1995ca2d9374bb12b02993b4ef13d"),
      ("catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php","d8f4aaea7bda3cf3272151b19e195ebe04d21486041046a20709c25b01e5c82b"),
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


EVALUATORS={"T001":eval_t001_t002,"T002":eval_t001_t002,"T003":eval_t003,"T004":eval_t004,"T005":eval_t005_t006,"T006":eval_t005_t006,"T007":eval_t007,"T008":eval_t008,"T009":eval_t009,"T010":eval_t010,"T012":eval_t012,"T013":eval_t013,"T014":eval_t014,"T015":eval_t015,"T017":eval_t017_t059,"T032":eval_t032,"T035":eval_t035,"T036":eval_t036_t039,"T039":eval_t036_t039,"T051":eval_t051,"T054":eval_t054,"T055":eval_t055,"T056":eval_t056,"T057":eval_t057,"T059":eval_t017_t059,"T060":eval_t060_t061,"T061":eval_t060_t061,"T067":eval_t067}

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
    lines+=["","T001–T008, T010, T032, T051, T054–T056 and T067 have **source-specific, SHA-pinned original compiled/native evidence evaluators** in this runner. Other blocked tests need their specified original compiled oracle; a new evaluator is added for an existing fixed T-test only after genuine source/native proof. No inferred PASS. Status ledger retains exact missing procedure. Missing acceptance is not a defect diagnosis.",""]
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
