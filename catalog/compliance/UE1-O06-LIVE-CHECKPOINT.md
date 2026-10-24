# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g5-t99-uobject-v227-source-flag-decision`
- **Stage:** Epic-source-equivalence-v1200-v432-common-modern-v60-61-wire-no-native-retests-v227-flag-fix-PASS30-method-BLOCKED
- **Last completed action:** Exact local Epic source compared, SHA-pinned 14 files. Modern v1200 vs inherited UT99 v400/v432 FStateFrame archived Node/StateNode/ProbeMask/LatentAction/AR_INDEX code offset same for flagged v60..61, so NO duplicate native tests for that common wire. v1200 unique oldver gates below40/47/51/52/55/57/58 and differences in source tagged property criteria remain separate. v227 Core has 67 header files and zero native cpp/binaries; common declared FStateFrame members but method equivalence unknown; v227 RF_PendingDelete=0x40000000, RF_HasArchtype=0x00000100 in RF_Load, not same as v1200/v432 RF_DebugSerialize=0x40000000. Actual production source fix in tagged UObject and UClass avoids false v227 post-load DebugSerialize/PendingDelete bits and returns unknown/null, leaves original on-disk flags and both v1200/v432 paths untouched. 21 staged original Gold v69 files 15688 exports zero flagged archetype (sample only). New single affected source-scoped DEV verifier PASS30/30 at 20261024T224950639Z-e10583ee; 0 native engine reruns, original compiled 308 v61 object identities SHA reused. Reconcile restores previous full source fingerprint 8a58b69... exactly from only two prechange reader bytes and all earlier source scopes true; G5 0FULL 0FAILED 8BLOCKED. PRD unchanged.
- **Resume at:** Do not retest common v1200/v432 modern 60-61 frame or native positives. Only resolve source-demonstrated historical v1200 early version branches and v227 actual archetype serializer path if real implementation/runtime becomes available, plus source-visible Camera load reachability and independent seven-criterion complete method acceptance. DEV only until verified tree can deploy compatibly to PRD.
- **Active changed at:** 2026-10-24T22:56:08+00:00
- **Latest DEV test states:** PASS 134; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-24T22:49:51.2358405Z | verify-ue1-o06-g5-uobject-v227-source-flag-decision.php | PASS | 188 | catalog\compliance\evidence\o06\20261024T224950639Z-e10583ee-DEV-verify-ue1-o06-g5-uobject-v227-source-flag-decision.php.txt |
| 2026-10-24T22:49:50.6468725Z | verify-ue1-o06-g5-uobject-v227-source-flag-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T224950639Z-e10583ee-DEV-verify-ue1-o06-g5-uobject-v227-source-flag-decision.php.txt |
| 2026-10-24T22:27:51.3003926Z | verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php | PASS | 112 | catalog\compliance\evidence\o06\20261024T222750785Z-7fe50f4a-DEV-verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php.txt |
| 2026-10-24T22:27:50.7923918Z | verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T222750785Z-7fe50f4a-DEV-verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php.txt |
| 2026-10-24T22:26:56.3150482Z | verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php | FAIL | 157 | catalog\compliance\evidence\o06\20261024T222655754Z-0ddb42bb-DEV-verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php.txt |
| 2026-10-24T22:26:55.7629259Z | verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T222655754Z-0ddb42bb-DEV-verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php.txt |
| 2026-10-24T22:10:18.2584787Z | verify-ue1-o06-g5-uobject-prd-native-string-decision.php | PASS | 315 | catalog\compliance\evidence\o06\20261024T221017518Z-bf35c3dd-DEV-verify-ue1-o06-g5-uobject-prd-native-string-decision.php.txt |
| 2026-10-24T22:10:17.5254772Z | verify-ue1-o06-g5-uobject-prd-native-string-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T221017518Z-bf35c3dd-DEV-verify-ue1-o06-g5-uobject-prd-native-string-decision.php.txt |
| 2026-10-24T21:36:55.5132768Z | verify-ue1-o06-g5-uobject-nested-vector-decision.php | PASS | 332 | catalog\compliance\evidence\o06\20261024T213654772Z-be2e37be-DEV-verify-ue1-o06-g5-uobject-nested-vector-decision.php.txt |
| 2026-10-24T21:36:54.7792759Z | verify-ue1-o06-g5-uobject-nested-vector-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T213654772Z-be2e37be-DEV-verify-ue1-o06-g5-uobject-nested-vector-decision.php.txt |
| 2026-10-24T21:20:03.3116417Z | verify-ue1-o06-g5-uobject-dynamic-array-decision.php | PASS | 310 | catalog\compliance\evidence\o06\20261024T212002584Z-35b1cb0a-DEV-verify-ue1-o06-g5-uobject-dynamic-array-decision.php.txt |
| 2026-10-24T21:20:02.5926285Z | verify-ue1-o06-g5-uobject-dynamic-array-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T212002584Z-35b1cb0a-DEV-verify-ue1-o06-g5-uobject-dynamic-array-decision.php.txt |
| 2026-10-24T21:19:12.2839445Z | verify-ue1-o06-g5-uobject-dynamic-array-decision.php | FAIL | 309 | catalog\compliance\evidence\o06\20261024T211911557Z-96028819-DEV-verify-ue1-o06-g5-uobject-dynamic-array-decision.php.txt |
| 2026-10-24T21:19:11.5659476Z | verify-ue1-o06-g5-uobject-dynamic-array-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T211911557Z-96028819-DEV-verify-ue1-o06-g5-uobject-dynamic-array-decision.php.txt |
| 2026-10-24T21:18:36.6227464Z | verify-ue1-o06-g5-uobject-dynamic-array-decision.php | FAIL | 311 | catalog\compliance\evidence\o06\20261024T211835901Z-ffac6a0e-DEV-verify-ue1-o06-g5-uobject-dynamic-array-decision.php.txt |
| 2026-10-24T21:18:35.9097802Z | verify-ue1-o06-g5-uobject-dynamic-array-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T211835901Z-ffac6a0e-DEV-verify-ue1-o06-g5-uobject-dynamic-array-decision.php.txt |
| 2026-10-24T20:49:46.6019352Z | verify-ue1-o06-g5-uobject-serializeitem-size-decision.php | PASS | 1115 | catalog\compliance\evidence\o06\20261024T204945069Z-47e2fb37-DEV-verify-ue1-o06-g5-uobject-serializeitem-size-decision.php.txt |
| 2026-10-24T20:49:45.0769594Z | verify-ue1-o06-g5-uobject-serializeitem-size-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T204945069Z-47e2fb37-DEV-verify-ue1-o06-g5-uobject-serializeitem-size-decision.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
