# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g3-03-c98-may17-original-bad-expr10-native-cursor`
- **Stage:** G3-03-C98-ALL-12-INVALID-SOURCE-TOKENS-54-PASS
- **Last completed action:** Extended pinned original May1998 UStruct malformed opcode fix to full set of 12 invalid low-range C98 script opcodes including 0x34 StructConst enum present but serializer switch absent. Native CDB opcode 0x10 and Tell24238 reused; remaining 11 source-switched by original v1.200 compiled-source snapshot, no new native claims. Single updated real-genuine C98 focus 54/54 DEV PASS; no other accepted tests rerun. Documented semantics and plan.
- **Resume at:** Commit/push this consolidated C98 switch-default fix and DEV-to-PRD 1:1 SHA. Next major distinct work: G3-03 original compiled SAVE and genuine C227k/v432 behaviours; no further isolated C98 invalid-token proofs.
- **Active changed at:** 2026-10-27T21:12:07+00:00
- **Latest DEV test states:** PASS 192; FAIL 1; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-27T21:08:32.2643067Z | verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php | PASS | 227 | catalog\compliance\evidence\o06\20261027T210831632Z-5ff1e421-DEV-verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php.txt |
| 2026-10-27T21:08:31.6393113Z | verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php | STARTED |  | catalog\compliance\evidence\o06\20261027T210831632Z-5ff1e421-DEV-verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php.txt |
| 2026-10-27T20:22:03.6743124Z | verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php | PASS | 122 | catalog\compliance\evidence\o06\20261027T202203144Z-ba5ab3d0-DEV-verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php.txt |
| 2026-10-27T20:22:03.1513119Z | verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php | STARTED |  | catalog\compliance\evidence\o06\20261027T202203144Z-ba5ab3d0-DEV-verify-ue1-o06-g3-03-c98-original-invalid-expr-token.php.txt |
| 2026-10-27T18:46:28.1586311Z | verify-ue1-o06-g3-03-c98-may1998-100-original-native-96-scripts.php | PASS | 141 | catalog\compliance\evidence\o06\20261027T184627555Z-2f411f41-DEV-verify-ue1-o06-g3-03-c98-may1998-100-original-native-96-scripts.php.txt |
| 2026-10-27T18:46:27.5656319Z | verify-ue1-o06-g3-03-c98-may1998-100-original-native-96-scripts.php | STARTED |  | catalog\compliance\evidence\o06\20261027T184627555Z-2f411f41-DEV-verify-ue1-o06-g3-03-c98-may1998-100-original-native-96-scripts.php.txt |
| 2026-10-27T18:25:31.5482663Z | verify-ue1-o06-g3-03-c227i-237-original-native-structs-223-scripts.php | PASS | 195 | catalog\compliance\evidence\o06\20261027T182530828Z-11e0d1b7-DEV-verify-ue1-o06-g3-03-c227i-237-original-native-structs-223-scripts.php.txt |
| 2026-10-27T18:25:30.8362654Z | verify-ue1-o06-g3-03-c227i-237-original-native-structs-223-scripts.php | STARTED |  | catalog\compliance\evidence\o06\20261027T182530828Z-11e0d1b7-DEV-verify-ue1-o06-g3-03-c227i-237-original-native-structs-223-scripts.php.txt |
| 2026-10-27T17:56:21.3333293Z | verify-ue1-o06-g3-08-original-227k-UAny-object7-null.php | PASS | 90 | catalog\compliance\evidence\o06\20261027T175620839Z-b42392c0-DEV-verify-ue1-o06-g3-08-original-227k-UAny-object7-null.php.txt |
| 2026-10-27T17:56:20.8463336Z | verify-ue1-o06-g3-08-original-227k-UAny-object7-null.php | STARTED |  | catalog\compliance\evidence\o06\20261027T175620839Z-b42392c0-DEV-verify-ue1-o06-g3-08-original-227k-UAny-object7-null.php.txt |
| 2026-10-27T17:41:50.5056642Z | verify-ue1-o06-g3-08-real-227k-native-Any-NAME5-zero.php | PASS | 91 | catalog\compliance\evidence\o06\20261027T174150013Z-0cd55989-DEV-verify-ue1-o06-g3-08-real-227k-native-Any-NAME5-zero.php.txt |
| 2026-10-27T17:41:50.0206654Z | verify-ue1-o06-g3-08-real-227k-native-Any-NAME5-zero.php | STARTED |  | catalog\compliance\evidence\o06\20261027T174150013Z-0cd55989-DEV-verify-ue1-o06-g3-08-real-227k-native-Any-NAME5-zero.php.txt |
| 2026-10-27T17:29:07.8258819Z | verify-ue1-o06-g3-08-c227k-real-native-Any-STRING4.php | PASS | 93 | catalog\compliance\evidence\o06\20261027T172907299Z-3ca3d406-DEV-verify-ue1-o06-g3-08-c227k-real-native-Any-STRING4.php.txt |
| 2026-10-27T17:29:07.3078557Z | verify-ue1-o06-g3-08-c227k-real-native-Any-STRING4.php | STARTED |  | catalog\compliance\evidence\o06\20261027T172907299Z-3ca3d406-DEV-verify-ue1-o06-g3-08-c227k-real-native-Any-STRING4.php.txt |
| 2026-10-27T16:58:14.2435925Z | verify-ue1-o06-g3-08-native-227k-real-Any-0to3.php | PASS | 177 | catalog\compliance\evidence\o06\20261027T165813475Z-fe58ae3f-DEV-verify-ue1-o06-g3-08-native-227k-real-Any-0to3.php.txt |
| 2026-10-27T16:58:13.4845565Z | verify-ue1-o06-g3-08-native-227k-real-Any-0to3.php | STARTED |  | catalog\compliance\evidence\o06\20261027T165813475Z-fe58ae3f-DEV-verify-ue1-o06-g3-08-native-227k-real-Any-0to3.php.txt |
| 2026-10-27T16:26:45.2063227Z | verify-ue1-o06-g3-08-469f-227k-native-pointer-button-any-ops.php | PASS | 113 | catalog\compliance\evidence\o06\20261027T162644568Z-e469751b-DEV-verify-ue1-o06-g3-08-469f-227k-native-pointer-button-any-ops.php.txt |
| 2026-10-27T16:26:44.5763234Z | verify-ue1-o06-g3-08-469f-227k-native-pointer-button-any-ops.php | STARTED |  | catalog\compliance\evidence\o06\20261027T162644568Z-e469751b-DEV-verify-ue1-o06-g3-08-469f-227k-native-pointer-button-any-ops.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
