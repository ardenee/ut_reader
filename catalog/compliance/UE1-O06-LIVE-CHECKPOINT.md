# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `t99-local-inherited-struct`
- **Stage:** released-verified
- **Last completed action:** Commit dd82e611 pushed to origin/main; only 3 runtime reader PHP files deployed to PRD, SHA-256 3/3 matched; DEV working tree clean before release checkpoint
- **Resume at:** O06 next contract: imported UStruct ancestry and linker source provenance; register a new focused plan before implementation
- **Active changed at:** 2026-10-23T11:44:02+00:00
- **Latest DEV test states:** PASS 93; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-23T11:35:39.8999730Z | verify-ue1-classic-array-inner-values.php | PASS | 233 | catalog\compliance\evidence\o06\20261023T113539356Z-f2a03c08-DEV-verify-ue1-classic-array-inner-values.php.txt |
| 2026-10-23T11:35:39.3577737Z | verify-ue1-classic-array-inner-values.php | STARTED |  | catalog\compliance\evidence\o06\20261023T113539356Z-f2a03c08-DEV-verify-ue1-classic-array-inner-values.php.txt |
| 2026-10-23T11:35:39.0288798Z | verify-ue1-classic-array-struct-members.php | PASS | 181 | catalog\compliance\evidence\o06\20261023T113538495Z-92fc017b-DEV-verify-ue1-classic-array-struct-members.php.txt |
| 2026-10-23T11:35:38.5037361Z | verify-ue1-classic-array-struct-members.php | STARTED |  | catalog\compliance\evidence\o06\20261023T113538495Z-92fc017b-DEV-verify-ue1-classic-array-struct-members.php.txt |
| 2026-10-23T11:04:21.7143585Z | verify-ue1-o06-payload-negatives.php | PASS | 213 | catalog\compliance\evidence\o06\20261023T110421305Z-d0837c42-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T11:04:21.3064696Z | verify-ue1-o06-payload-negatives.php | STARTED |  | catalog\compliance\evidence\o06\20261023T110421305Z-d0837c42-DEV-verify-ue1-o06-payload-negatives.php.txt |
| 2026-10-23T11:04:21.0961700Z | verify-ue1-v68-v69-genuine-corpus.php | PASS | 186 | catalog\compliance\evidence\o06\20261023T110420727Z-697ca3c8-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T11:04:20.7288794Z | verify-ue1-v68-v69-genuine-corpus.php | STARTED |  | catalog\compliance\evidence\o06\20261023T110420727Z-697ca3c8-DEV-verify-ue1-v68-v69-genuine-corpus.php.txt |
| 2026-10-23T11:04:20.5194304Z | verify-ue1-o06-genuine-compiled-native.php | PASS | 8374 | catalog\compliance\evidence\o06\20261023T110411963Z-fbbfe208-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |
| 2026-10-23T11:04:11.9631900Z | verify-ue1-o06-genuine-compiled-native.php | STARTED |  | catalog\compliance\evidence\o06\20261023T110411963Z-fbbfe208-DEV-verify-ue1-o06-genuine-compiled-native.php.txt |
| 2026-10-23T11:04:11.7618989Z | verify-ue1-o06-genuine-class-default-struct-values.php | PASS | 3319 | catalog\compliance\evidence\o06\20261023T110408263Z-1a8ac4c3-DEV-verify-ue1-o06-genuine-class-default-struct-values.php.txt |
| 2026-10-23T11:04:08.2639072Z | verify-ue1-o06-genuine-class-default-struct-values.php | STARTED |  | catalog\compliance\evidence\o06\20261023T110408263Z-1a8ac4c3-DEV-verify-ue1-o06-genuine-class-default-struct-values.php.txt |
| 2026-10-23T11:04:08.0578210Z | verify-ue1-o06-genuine-class-default-references.php | PASS | 3279 | catalog\compliance\evidence\o06\20261023T110404592Z-0468830c-DEV-verify-ue1-o06-genuine-class-default-references.php.txt |
| 2026-10-23T11:04:04.5922797Z | verify-ue1-o06-genuine-class-default-references.php | STARTED |  | catalog\compliance\evidence\o06\20261023T110404592Z-0468830c-DEV-verify-ue1-o06-genuine-class-default-references.php.txt |
| 2026-10-23T11:04:04.3858916Z | verify-ue1-o06-genuine-class-default-declarations.php | PASS | 3905 | catalog\compliance\evidence\o06\20261023T110400284Z-2ce10797-DEV-verify-ue1-o06-genuine-class-default-declarations.php.txt |
| 2026-10-23T11:04:00.2856918Z | verify-ue1-o06-genuine-class-default-declarations.php | STARTED |  | catalog\compliance\evidence\o06\20261023T110400284Z-2ce10797-DEV-verify-ue1-o06-genuine-class-default-declarations.php.txt |
| 2026-10-23T11:04:00.0692550Z | verify-ue1-classic-map-no-data.php | PASS | 102 | catalog\compliance\evidence\o06\20261023T110359775Z-38bb6fa9-DEV-verify-ue1-classic-map-no-data.php.txt |
| 2026-10-23T11:03:59.7751702Z | verify-ue1-classic-map-no-data.php | STARTED |  | catalog\compliance\evidence\o06\20261023T110359775Z-38bb6fa9-DEV-verify-ue1-classic-map-no-data.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
