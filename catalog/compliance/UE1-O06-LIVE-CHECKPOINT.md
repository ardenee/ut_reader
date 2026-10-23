# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g3g4-source-provenance-engine-first-game-overrides`
- **Stage:** implemented-not-native-verified
- **Last completed action:** Corrected critical UE1 core/game provenance bug: removed version>=62 -> UT99 inference from source selector; automatic Unreal v1.227 only from source-verified version 69 and licensee 227; UT99 v400 now requires explicit or external established loader provenance, unknown >=62 is source-unresolved. Pass selected source through CLI, classic UObject, UClass, native, tagged and nested/default readers. Fixed v227 archive class names from wrong C++ AX* to actual UnrealScript XBeamEmitter XParticleEmitter XRopeDeco, with Emitter source package ClassIndex identity and proper game loader context (including older compatible packages). Engine base always UE1, game overlays after inherited UObject. Implemented recursive UStructProperty::SerializeItem through UStruct::SerializeBin for nested typed class defaults/array structs and zero-byte empty layouts; unresolved/cyclic still explicit. Wrote binding architecture rule for UE1-UE5 in AGENTS and specs/engine-game-source-precedence.md. Keep two C98/T99 UClass PARTIAL, register 252 mapped 2 partial 38 excluded. PHP syntax+git diff only; no functional tests or PRD deploy.
- **Resume at:** Finish two remaining C98 and T99 UClass::Serialize implementation gaps from actual Epic source (unsupported default property kinds, imported declarations and source-typed values), then separately native G5/corpus verification and safe PRD deployment. Apply engine->game code composition consistently for future UE2-UE5 work.
- **Active changed at:** 2026-10-23T20:14:50+00:00
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
