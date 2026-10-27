# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g3-02-v227-source-introduced-property-inherited-serialize`
- **Stage:** G3-02-V227-NEW-DECLARED-INHERITED-PROPERTIES-20-PASS-RELEASE-PENDING
- **Last completed action:** After accepted G3-01 chronological game sources, implemented actual later v227 changed original UnType.h class additions: UPointerProperty, UAnyProperty have NO Serialize override and hence inherit UE1 UField::Serialize+UProperty::Serialize declaration with no native extra bytes, source v227 gated, preserve 4-bit GetID NAME_IntProperty/NAME_VectorProperty aliases and distinct unverified runtime SerializeItem. Newly introduced UButtonProperty declares Serialize(FArchive&) so overrides inherited source and cannot be reported as complete with unavailable method body. New focused source/payload one-shot 20/20 DEV PASS, no 2012 compiled samples of introduced classes in checked Core/Engine/Editor/UMenu. G3-02 has actual code fix but other changed method classes OPEN; G3-01 source policy FULL ACCEPTED 1/16, no repeat old compiled property/field tests.
- **Resume at:** Commit actual G3-02 source-directed reader fix, release GitHub and PRD byte-SHA. Continue G3-02 remaining explicit new/changed v1.224/227 headers method semantics; no need to recover absent game Core.cpp, 14 ABI differences not global blocker.
- **Active changed at:** 2026-10-27T10:36:07+00:00
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
