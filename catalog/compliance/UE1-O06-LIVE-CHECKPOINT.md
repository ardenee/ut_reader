# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `t99-genuine-serializeexpr`
- **Stage:** ready-to-release
- **Last completed action:** Pinned six installed UT99 v68/v69 .u SHA manifest: 58/58 focus PASS, 3194 UClass/11366 default tags, nonzero local NAME_None index proven; 30-package pinned gate 246/246 PASS, 4199 UClass/17700 tags zero decoding exceptions; scoped gate 6/6 PASS with five earlier matches reused.
- **Resume at:** Commit source/manifest/fixtures and real evidence, push origin/main, deploy only CatalogUE1ClassicScriptArchiveReader.php to PRD via SHA256 without PRD PHP tests.
- **Active changed at:** 2026-10-23T13:19:26+00:00
- **Latest DEV test states:** PASS 96; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-23T13:16:28.6810071Z | verify-ue1-o06-genuine-ut99-full-scripts.php | PASS | 11984 | catalog\compliance\evidence\o06\20261023T131616365Z-482253ed-DEV-verify-ue1-o06-genuine-ut99-full-scripts.php.txt |
| 2026-10-23T13:16:16.3729686Z | verify-ue1-o06-genuine-ut99-full-scripts.php | STARTED |  | catalog\compliance\evidence\o06\20261023T131616365Z-482253ed-DEV-verify-ue1-o06-genuine-ut99-full-scripts.php.txt |
| 2026-10-23T13:11:40.5268553Z | verify-ue1-o06-payload-negatives.php | PASS | 199 | catalog\compliance\evidence\o06\20261023T131140023Z-8536e26d-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T13:11:40.0236415Z | verify-ue1-o06-payload-negatives.php | STARTED |  | catalog\compliance\evidence\o06\20261023T131140023Z-8536e26d-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T13:11:39.7010111Z | verify-ue1-v68-v69-genuine-corpus.php | PASS | 179 | catalog\compliance\evidence\o06\20261023T131139203Z-55b1b84e-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T13:11:39.2034954Z | verify-ue1-v68-v69-genuine-corpus.php | STARTED |  | catalog\compliance\evidence\o06\20261023T131139203Z-55b1b84e-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T13:11:38.8837312Z | verify-ue1-o06-genuine-class-default-declarations.php | PASS | 3462 | catalog\compliance\evidence\o06\20261023T131135091Z-4ec33b3c-DEV-verify-ue1-o06-genuine-class-default-declarations.php.txt |
| 2026-10-23T13:11:35.0912536Z | verify-ue1-o06-genuine-class-default-declarations.php | STARTED |  | catalog\compliance\evidence\o06\20261023T131135091Z-4ec33b3c-DEV-verify-ue1-o06-genuine-class-default-declarations.php.txt |
| 2026-10-23T13:11:34.7406269Z | verify-ue1-o06-genuine-compiled-native.php | PASS | 7663 | catalog\compliance\evidence\o06\20261023T131126724Z-790287d8-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |
| 2026-10-23T13:11:26.7328825Z | verify-ue1-o06-genuine-compiled-native.php | STARTED |  | catalog\compliance\evidence\o06\20261023T131126724Z-790287d8-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |
| 2026-10-23T13:10:36.6592494Z | verify-ue1-o06-genuine-ut99-script-archive.php | PASS | 7314 | catalog\compliance\evidence\o06\20261023T131028993Z-00f114c6-DEV-verify-ue1-o06-genuine-ut99-script-archive.php.txt |
| 2026-10-23T13:10:29.0004234Z | verify-ue1-o06-genuine-ut99-script-archive.php | STARTED |  | catalog\compliance\evidence\o06\20261023T131028993Z-00f114c6-DEV-verify-ue1-o06-genuine-ut99-script-archive.php.txt |
| 2026-10-23T12:50:42.9714944Z | verify-ue1-o06-payload-negatives.php | PASS | 194 | catalog\compliance\evidence\o06\20261023T125042476Z-a88a3ccc-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T12:50:42.4778674Z | verify-ue1-o06-payload-negatives.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125042476Z-a88a3ccc-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T12:50:42.1274302Z | verify-ue1-v68-v69-genuine-corpus.php | PASS | 187 | catalog\compliance\evidence\o06\20261023T125041636Z-4a4254c0-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T12:50:41.6362907Z | verify-ue1-v68-v69-genuine-corpus.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125041636Z-4a4254c0-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T12:50:41.3063176Z | verify-ue1-o06-genuine-compiled-native.php | PASS | 7537 | catalog\compliance\evidence\o06\20261023T125033473Z-c3483900-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |
| 2026-10-23T12:50:33.4735768Z | verify-ue1-o06-genuine-compiled-native.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125033473Z-c3483900-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
