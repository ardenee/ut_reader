# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g3-02-v227-source-introduced-property-inherited-serialize`
- **Stage:** G3-01-FULL-SOURCE-87-G3-02-V227-DECLARATIONS-20-PRD-SHA-VERIFIED
- **Last completed action:** User confirmed cumulative game-source lineage rule through Unreal v0.84a to v1.227. G3-01 SOURCE SELECTION accepted 1/16 after pinned original eight-Unreal and UT99 v400->v432 source snapshots and 87/87 scoped DEV PASS, release 887d87fc GitHub pushed PRD 16/16 SHA matched. G3-02 actual added game class source: UPointerProperty and UAnyProperty inherit UField/UProperty native serialized declaration (no explicit Serialize override) and retain source-tag GetID aliases; UButtonProperty explicitly declares Serialize(FArchive&) therefore remains SOURCE-SPECIFIC UNVERIFIED, not guessed inherited. Single new source anchored 20/20 focused DEV PASS, actual implementation release 499de57a GitHub pushed, PRD 16/16 SHA files matched. Neither missing v227 cpp nor mismatched 2012 Core.lib inhibits source inheritance; remaining G3-02 changed header methods and behavior-specific native G3 tests are open, final gate 1/16.
- **Resume at:** Continue G3-02 complete actual source-defined C227 v1.224->v1.227 changed header/inline method and new-class inventory and UT99 v400->v432 deltas, track inherited vs overwritten per class/method; then close remaining contracts. Do not retest G3-01 87, G3-02 20, earlier 668/915/1536/600 proofs or compel missing game .cpp reconstruction.
- **Active changed at:** 2026-10-27T10:36:47+00:00
- **Latest DEV test states:** PASS 173; FAIL 1; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-27T10:34:02.0585864Z | verify-ue1-o06-g3-02-v227-new-property-inheritance.php | PASS | 128 | catalog\compliance\evidence\o06\20261027T103401493Z-e63185bd-DEV-verify-ue1-o06-g3-02-v227-new-property-inheritance.php.txt |
| 2026-10-27T10:34:01.5016249Z | verify-ue1-o06-g3-02-v227-new-property-inheritance.php | STARTED |  | catalog\compliance\evidence\o06\20261027T103401493Z-e63185bd-DEV-verify-ue1-o06-g3-02-v227-new-property-inheritance.php.txt |
| 2026-10-27T10:25:03.6571850Z | verify-ue1-o06-g3-01-chronological-source-inheritance.php | PASS | 95 | catalog\compliance\evidence\o06\20261027T102503120Z-98b1e087-DEV-verify-ue1-o06-g3-01-chronological-source-inheritance.php.txt |
| 2026-10-27T10:25:03.1288502Z | verify-ue1-o06-g3-01-chronological-source-inheritance.php | STARTED |  | catalog\compliance\evidence\o06\20261027T102503120Z-98b1e087-DEV-verify-ue1-o06-g3-01-chronological-source-inheritance.php.txt |
| 2026-10-27T09:39:04.8367851Z | verify-ue1-o06-g3-c227i-ufield-915-native-references.php | PASS | 334 | catalog\compliance\evidence\o06\20261027T093904069Z-57ab140a-DEV-verify-ue1-o06-g3-c227i-ufield-915-native-references.php.txt |
| 2026-10-27T09:39:04.0778101Z | verify-ue1-o06-g3-c227i-ufield-915-native-references.php | STARTED |  | catalog\compliance\evidence\o06\20261027T093904069Z-57ab140a-DEV-verify-ue1-o06-g3-c227i-ufield-915-native-references.php.txt |
| 2026-10-27T09:24:27.3061131Z | verify-ue1-o06-g3-c227i-uproperty-668-native-identities.php | PASS | 271 | catalog\compliance\evidence\o06\20261027T092426607Z-70bebb8d-DEV-verify-ue1-o06-g3-c227i-uproperty-668-native-identities.php.txt |
| 2026-10-27T09:24:26.6151476Z | verify-ue1-o06-g3-c227i-uproperty-668-native-identities.php | STARTED |  | catalog\compliance\evidence\o06\20261027T092426607Z-70bebb8d-DEV-verify-ue1-o06-g3-c227i-uproperty-668-native-identities.php.txt |
| 2026-10-27T06:16:10.0505610Z | verify-ue1-o06-g3-c98-may1998-two-native-malformed-cursors.php | PASS | 118 | catalog\compliance\evidence\o06\20261027T061609517Z-f2803fc7-DEV-verify-ue1-o06-g3-c98-may1998-two-native-malformed-cursors.php.txt |
| 2026-10-27T06:16:09.5255598Z | verify-ue1-o06-g3-c98-may1998-two-native-malformed-cursors.php | STARTED |  | catalog\compliance\evidence\o06\20261027T061609517Z-f2803fc7-DEV-verify-ue1-o06-g3-c98-may1998-two-native-malformed-cursors.php.txt |
| 2026-10-26T23:26:15.1681049Z | verify-ue1-o06-g3-c98-may1998-600-native-references.php | PASS | 281 | catalog\compliance\evidence\o06\20261026T232614467Z-33861503-DEV-verify-ue1-o06-g3-c98-may1998-600-native-references.php.txt |
| 2026-10-26T23:26:14.4761067Z | verify-ue1-o06-g3-c98-may1998-600-native-references.php | STARTED |  | catalog\compliance\evidence\o06\20261026T232614467Z-33861503-DEV-verify-ue1-o06-g3-c98-may1998-600-native-references.php.txt |
| 2026-10-26T23:05:04.4062462Z | verify-ue1-o06-g3-c98-may1998-ustring-native-loaded.php | PASS | 158 | catalog\compliance\evidence\o06\20261026T230503824Z-c26a0589-DEV-verify-ue1-o06-g3-c98-may1998-ustring-native-loaded.php.txt |
| 2026-10-26T23:05:03.8322345Z | verify-ue1-o06-g3-c98-may1998-ustring-native-loaded.php | STARTED |  | catalog\compliance\evidence\o06\20261026T230503824Z-c26a0589-DEV-verify-ue1-o06-g3-c98-may1998-ustring-native-loaded.php.txt |
| 2026-10-26T22:55:37.5186474Z | verify-ue1-o06-g3-c98-may1998-uproperty-identities.php | PASS | 662 | catalog\compliance\evidence\o06\20261026T225536408Z-cc8998e6-DEV-verify-ue1-o06-g3-c98-may1998-uproperty-identities.php.txt |
| 2026-10-26T22:55:36.4156731Z | verify-ue1-o06-g3-c98-may1998-uproperty-identities.php | STARTED |  | catalog\compliance\evidence\o06\20261026T225536408Z-cc8998e6-DEV-verify-ue1-o06-g3-c98-may1998-uproperty-identities.php.txt |
| 2026-10-26T22:09:15.9646833Z | verify-ue1-o06-g3-c98-may1998-original-stock.php | PASS | 2452 | catalog\compliance\evidence\o06\20261026T220913071Z-d94680b3-DEV-verify-ue1-o06-g3-c98-may1998-original-stock.php.txt |
| 2026-10-26T22:09:13.0795018Z | verify-ue1-o06-g3-c98-may1998-original-stock.php | STARTED |  | catalog\compliance\evidence\o06\20261026T220913071Z-d94680b3-DEV-verify-ue1-o06-g3-c98-may1998-original-stock.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
