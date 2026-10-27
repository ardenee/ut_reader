# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g3-03-c98-may17-original-bad-expr10-native-cursor`
- **Stage:** G3-03-C98-INVALID-OPCODE10-ORIGINAL-NATIVE-CURSOR-23-PASS
- **Last completed action:** Fixed reproducible real product divergence: malformed source-invalid C98 UStruct script opcode0x10 original shipped Core.dll appErrorf at FArchive Tell24238; UnrealDB previously returned script_archive_token_unimplemented, now throws after consuming opcode, including source-equivalent 0x11, modern source retains existing handling. New original genuine + one-byte mutation CDB SHA-pinned and one new scoped 23/23 DEV PASS with all older suites untouched. Docs/source/test status updated; G3 remains5/16.
- **Resume at:** Release and verify SHA identity DEV to PRD for this verified reader plus compliance, no PRD PHP tests; after release choose the next genuinely missing full G3-03 native SAVE/malformed or G3-04 original function/state contract, never repeat C98 positive 100/96 or C227i 237/223.
- **Active changed at:** 2026-10-27T20:24:16+00:00
- **Latest DEV test states:** PASS 192; FAIL 1; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
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
| 2026-10-27T16:08:41.9209415Z | verify-ue1-o06-g3-09-full-real-class-object-references.php | PASS | 154 | catalog\compliance\evidence\o06\20261027T160841124Z-d60432a3-DEV-verify-ue1-o06-g3-09-full-real-class-object-references.php.txt |
| 2026-10-27T16:08:41.1339427Z | verify-ue1-o06-g3-09-full-real-class-object-references.php | STARTED |  | catalog\compliance\evidence\o06\20261027T160841124Z-d60432a3-DEV-verify-ue1-o06-g3-09-full-real-class-object-references.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
