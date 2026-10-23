# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `t99-struct-field-iterator-filter`
- **Stage:** released-verified
- **Last completed action:** Committed f4e26b64, pushed and SHA deployed single UT99 struct field iterator reader 6A02DDE6...E052; 22/22 focused and 5/5 gate passed; no PRD PHP tests.
- **Resume at:** Prioritise independent native Epic UT99 runtime oracle and genuine imported UStruct datasets; do not accept nested struct solely from synthetic packages. New source contract must use focused test plan.
- **Active changed at:** 2026-10-23T12:54:22+00:00
- **Latest DEV test states:** PASS 94; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-23T12:50:42.9714944Z | verify-ue1-o06-payload-negatives.php | PASS | 194 | catalog\compliance\evidence\o06\20261023T125042476Z-a88a3ccc-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T12:50:42.4778674Z | verify-ue1-o06-payload-negatives.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125042476Z-a88a3ccc-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T12:50:42.1274302Z | verify-ue1-v68-v69-genuine-corpus.php | PASS | 187 | catalog\compliance\evidence\o06\20261023T125041636Z-4a4254c0-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T12:50:41.6362907Z | verify-ue1-v68-v69-genuine-corpus.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125041636Z-4a4254c0-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T12:50:41.3063176Z | verify-ue1-o06-genuine-compiled-native.php | PASS | 7537 | catalog\compliance\evidence\o06\20261023T125033473Z-c3483900-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |
| 2026-10-23T12:50:33.4735768Z | verify-ue1-o06-genuine-compiled-native.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125033473Z-c3483900-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |
| 2026-10-23T12:50:33.1522195Z | verify-ue1-classic-array-struct-members.php | PASS | 157 | catalog\compliance\evidence\o06\20261023T125032653Z-d8e8ca3a-DEV-verify-ue1-classic-array-struct-members.php.txt |
| 2026-10-23T12:50:32.6601573Z | verify-ue1-classic-array-struct-members.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125032653Z-d8e8ca3a-DEV-verify-ue1-classic-array-struct-members.php.txt |
| 2026-10-23T12:50:13.9135740Z | verify-ue1-classic-imported-struct-ancestry.php | PASS | 137 | catalog\compliance\evidence\o06\20261023T125013420Z-27372288-DEV-verify-ue1-classic-imported-struct-ancestry.php.txt |
| 2026-10-23T12:50:13.4292043Z | verify-ue1-classic-imported-struct-ancestry.php | STARTED |  | catalog\compliance\evidence\o06\20261023T125013420Z-27372288-DEV-verify-ue1-classic-imported-struct-ancestry.php.txt |
| 2026-10-23T12:49:11.1841704Z | verify-ue1-classic-imported-struct-ancestry.php | PASS | 125 | catalog\compliance\evidence\o06\20261023T124910722Z-831b21bf-DEV-verify-ue1-classic-imported-struct-ancestry.php.txt |
| 2026-10-23T12:49:10.7292112Z | verify-ue1-classic-imported-struct-ancestry.php | STARTED |  | catalog\compliance\evidence\o06\20261023T124910722Z-831b21bf-DEV-verify-ue1-classic-imported-struct-ancestry.php.txt |
| 2026-10-23T12:48:11.0965009Z | verify-ue1-classic-imported-struct-ancestry.php | FAIL | 174 | catalog\compliance\evidence\o06\20261023T124810591Z-23a432f2-DEV-verify-ue1-classic-imported-struct-ancestry.php.txt |
| 2026-10-23T12:48:10.5986804Z | verify-ue1-classic-imported-struct-ancestry.php | STARTED |  | catalog\compliance\evidence\o06\20261023T124810591Z-23a432f2-DEV-verify-ue1-classic-imported-struct-ancestry.php.txt |
| 2026-10-23T12:40:24.3201745Z | verify-ue1-o06-payload-negatives.php | PASS | 209 | catalog\compliance\evidence\o06\20261023T124023809Z-54362be1-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T12:40:23.8093910Z | verify-ue1-o06-payload-negatives.php | STARTED |  | catalog\compliance\evidence\o06\20261023T124023809Z-54362be1-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T12:40:23.4957786Z | verify-ue1-v68-v69-genuine-corpus.php | PASS | 175 | catalog\compliance\evidence\o06\20261023T124023018Z-39a423b7-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T12:40:23.0196801Z | verify-ue1-v68-v69-genuine-corpus.php | STARTED |  | catalog\compliance\evidence\o06\20261023T124023018Z-39a423b7-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
