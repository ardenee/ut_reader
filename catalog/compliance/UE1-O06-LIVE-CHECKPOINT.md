# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `rea-ghidra-compiled-v432-analyzer-installed`
- **Stage:** source-file-mapped-rea-ghidra-verified-g5-open
- **Last completed action:** Installed official rea-agents 6.7.0 npm global and SHA256 verified official Ghidra 12.1.4 on DEV; configured Codex REA MCP and global skill without altering unrelated config, created UnrealDB source-parity scoped skill and reusable REA PowerShell launcher under C:\Temp\UnrealDB\O06\tools. REA doctor Ghidra 12.1.4 x86 PE readiness healthy 10 checks. First inspect of disposable installed UT99 v432 Engine.dll SHA256 f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac succeeded and native Ghidra decompile of UAnimation::Serialize at 0x103ECEC0 recovered UObject::Serialize then archive calls to this+0x28/+0x34/+0x40 matching earlier RefBones Moves AnimSeqs source mapping. Evidence ID ev_3a0aa70a7ee584a6701c4b7569ca849f3ccfce05d6b8a7288a0ebae5c39b796b. Raw evidence stays C:\Temp, method provenance and REA setup added to catalog/compliance. O06 register unchanged 256 MAPPED 0 PARTIAL 38 EXCLUDED; G5 genuine loaded values still OPEN and PRD untouched.
- **Resume at:** Use REA+Ghidra scoped native decompilation on Core.dll and Engine.dll to check v432 differences in inherited v400 Serialize methods one by one, source/compiled-priority per AGENTS.md. Confirm genuine package runtime-loaded value comparisons in G5 before accepting full UE1 parity; no broad repeat tests or PRD changes absent accepted release gate.
- **Active changed at:** 2026-10-23T21:44:55+00:00
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
