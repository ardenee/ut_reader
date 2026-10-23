# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g3-ue1-loader-warning-and-class-fixedstrings`
- **Stage:** implemented-pending-verification-no-tests
- **Last completed action:** Corrected UE1 game version gates to Epic native CheckVersion semantics: pinned Unreal v1.200 and UT99 v400 warn+may-continue below PACKAGE_MIN_VERSION; PACKAGE_FILE_VERSION is the saver version, NOT a checked upper loader rejection. Retain declared 34-61, 60-68 and 60-69 as candidate-priority ranges and provide source-specific loaderVersionDiagnostic with below-min warning/above-save notes. Explicit known loader provenance may attempt outside range without pretending that it passed native loading; unknown overlapping profiles still unresolved. Source v1.227 linker unavailable so no invented runtime warning behavior. Added source-defined original v1.200 UStringProperty::SerializeItem FArchive::String into recursive nested UE1 UClass struct/array value reader, bytewise read up to StringSize-1 or NUL and synthesized in-memory NUL without on-disk byte. O06 source register 252 MAPPED 2 PARTIAL 38 EXCLUDED; 2 UClass complex defaults still open; only 3 PHP syntax/diff checks, no native/corpus tests, PRD untouched.
- **Resume at:** Continue C98/T99 UClass::Serialize complex defaults source implementation from Epic field serializers, especially nested fixed arrays/imported layouts; promote entries only when file-read mapping code complete. Then separate G5 compiled native oracle/corpus verification, release review and PRD deployment.
- **Active changed at:** 2026-10-23T20:31:11+00:00
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
