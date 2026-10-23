# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g3-ue1-uclass-source-file-archive-mapped-all`
- **Stage:** source-file-implementation-mapped-verification-deferred
- **Last completed action:** Completed last two O06 source-to-production FILE SERIALIZATION mappings: C98 Unreal v1.200 UClass::Serialize and T99 UT99 v400 UClass::Serialize. Compared pinned C++ method bodies with inherited production UClass/UState/UStruct/UField/UObject archive, script expressions, ClassRecordSize/ClassFlags/ClassGuid, FDependency and PackageImports, ClassWithin/ClassConfigName, default tagged properties and source v1.200 pre57 trailing FName pair. Implemented UT99 <=61 loaded RF_Public\|RF_Standalone flags and ClassWithin UObject::StaticClass as load outcomes (no invented file fields), fixed UStringProperty bytes without NUL at declared capacity, and UMapProperty nested zero-byte SerializeItem when genuine local Key/Value declarations exist. ClassMetadata now reports precise source_file_archive_complete vs unconsumed source bytes, and retains runtime_default_object_effects_executed=false; imported/missing external packages and unknown typed default memories remain explicit unverified outcomes. Register now 254 MAPPED 0 PARTIAL 38 EXCLUDED. Syntax/diff checks only, no compiled native test campaign or PRD deployment.
- **Resume at:** Begin separately planned native G5 compiled Epic/genuine corpus verification of mapped 254 source methods and UClass loaded values, including imported dependency availability and object memory initialization; only after acceptance and safe deploy to PRD. Mapping completion is NOT native-loaded-values parity acceptance.
- **Active changed at:** 2026-10-23T20:41:02+00:00
- **Latest DEV test states:** PASS 102; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-23T16:15:31.1963923Z | verify-ue1-o06-native-ut99-music-all.php | PASS | 317 | catalog\compliance\evidence\o06\20261023T161530485Z-7237246f-DEV-verify-ue1-o06-native-ut99-music-all.php.txt |
| 2026-10-23T16:15:30.4943587Z | verify-ue1-o06-native-ut99-music-all.php | STARTED |  | catalog\compliance\evidence\o06\20261023T161530485Z-7237246f-DEV-verify-ue1-o06-native-ut99-music-all.php.txt |
| 2026-10-23T15:31:10.6746983Z | verify-ue1-o06-native-ut99-sound-exports.php | PASS | 313 | catalog\compliance\evidence\o06\20261023T153110017Z-e725eeae-DEV-verify-ue1-o06-native-ut99-sound-exports.php.txt |
| 2026-10-23T15:31:10.0246982Z | verify-ue1-o06-native-ut99-sound-exports.php | STARTED |  | catalog\compliance\evidence\o06\20261023T153110017Z-e725eeae-DEV-verify-ue1-o06-native-ut99-sound-exports.php.txt |
| 2026-10-23T15:15:43.4249671Z | verify-ue1-o06-g2-stacknode-may-july.php | PASS | 220 | catalog\compliance\evidence\o06\20261023T151542836Z-4be7e371-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |
| 2026-10-23T15:15:42.8449667Z | verify-ue1-o06-g2-stacknode-may-july.php | STARTED |  | catalog\compliance\evidence\o06\20261023T151542836Z-4be7e371-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |
| 2026-10-23T15:15:21.4361604Z | verify-ue1-original-unrealfile-special-headers.php | PASS | 246 | catalog\compliance\evidence\o06\20261023T151520885Z-f9161c10-DEV-verify-ue1-original-unrealfile-special-headers.php.txt |
| 2026-10-23T15:15:20.8851934Z | verify-ue1-original-unrealfile-special-headers.php | STARTED |  | catalog\compliance\evidence\o06\20261023T151520885Z-f9161c10-DEV-verify-ue1-original-unrealfile-special-headers.php.txt |
| 2026-10-23T15:15:20.5411593Z | verify-ue1-original-unrealfile-stacktree-data.php | PASS | 120 | catalog\compliance\evidence\o06\20261023T151520102Z-0d4219e4-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T15:15:20.1021637Z | verify-ue1-original-unrealfile-stacktree-data.php | STARTED |  | catalog\compliance\evidence\o06\20261023T151520102Z-0d4219e4-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T15:15:19.7561644Z | verify-ue1-o06-g2-stacknode-may-july.php | FAIL | 251 | catalog\compliance\evidence\o06\20261023T151519158Z-62d03a1c-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |
| 2026-10-23T15:15:19.1661914Z | verify-ue1-o06-g2-stacknode-may-july.php | STARTED |  | catalog\compliance\evidence\o06\20261023T151519158Z-62d03a1c-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |
| 2026-10-23T14:52:24.0243360Z | verify-ue1-original-unrealfile-special-headers.php | PASS | 416 | catalog\compliance\evidence\o06\20261023T145223257Z-72c45fe9-DEV-verify-ue1-original-unrealfile-special-headers.php.txt |
| 2026-10-23T14:52:23.2651546Z | verify-ue1-original-unrealfile-special-headers.php | STARTED |  | catalog\compliance\evidence\o06\20261023T145223257Z-72c45fe9-DEV-verify-ue1-original-unrealfile-special-headers.php.txt |
| 2026-10-23T14:51:20.1061555Z | verify-ue1-original-unrealfile-stacktree-data.php | PASS | 162 | catalog\compliance\evidence\o06\20261023T145119580Z-36cb7fa3-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T14:51:19.5878087Z | verify-ue1-original-unrealfile-stacktree-data.php | STARTED |  | catalog\compliance\evidence\o06\20261023T145119580Z-36cb7fa3-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T14:51:10.8345150Z | verify-ue1-o06-g2-stacknode-may-july.php | PASS | 270 | catalog\compliance\evidence\o06\20261023T145110206Z-346cc5b6-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |
| 2026-10-23T14:51:10.2136563Z | verify-ue1-o06-g2-stacknode-may-july.php | STARTED |  | catalog\compliance\evidence\o06\20261023T145110206Z-346cc5b6-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
