# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g2-original-1997-stacknode`
- **Stage:** passed-gate-deployed
- **Last completed action:** Epic May four vs July five FStackNode links and NestType/StackNodeFlags field order corrected; 50/50 source-backed verifier, original stacktree and special-header test PASS on DEV; production reader SHA256 matched DEV and PRD
- **Resume at:** Continue first genuinely unverified May/July build-selected serialization contract; do not redo passing FStackNode tests without changed inputs. G2-G5 still open, no original compiled engine oracle.
- **Active changed at:** 2026-10-23T14:54:58+00:00
- **Latest DEV test states:** PASS 100; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-23T14:52:24.0243360Z | verify-ue1-original-unrealfile-special-headers.php | PASS | 416 | catalog\compliance\evidence\o06\20261023T145223257Z-72c45fe9-DEV-verify-ue1-original-unrealfile-special-headers.php.txt |
| 2026-10-23T14:52:23.2651546Z | verify-ue1-original-unrealfile-special-headers.php | STARTED |  | catalog\compliance\evidence\o06\20261023T145223257Z-72c45fe9-DEV-verify-ue1-original-unrealfile-special-headers.php.txt |
| 2026-10-23T14:51:20.1061555Z | verify-ue1-original-unrealfile-stacktree-data.php | PASS | 162 | catalog\compliance\evidence\o06\20261023T145119580Z-36cb7fa3-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T14:51:19.5878087Z | verify-ue1-original-unrealfile-stacktree-data.php | STARTED |  | catalog\compliance\evidence\o06\20261023T145119580Z-36cb7fa3-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T14:51:10.8345150Z | verify-ue1-o06-g2-stacknode-may-july.php | PASS | 270 | catalog\compliance\evidence\o06\20261023T145110206Z-346cc5b6-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |
| 2026-10-23T14:51:10.2136563Z | verify-ue1-o06-g2-stacknode-may-july.php | STARTED |  | catalog\compliance\evidence\o06\20261023T145110206Z-346cc5b6-DEV-verify-ue1-o06-g2-stacknode-may-july.php.txt |
| 2026-10-23T14:49:06.1529636Z | verify-ue1-original-unrealfile-stacktree-data.php | FAIL | 72 | catalog\compliance\evidence\o06\20261023T144905734Z-acfa2327-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T14:49:05.7417419Z | verify-ue1-original-unrealfile-stacktree-data.php | STARTED |  | catalog\compliance\evidence\o06\20261023T144905734Z-acfa2327-DEV-verify-ue1-original-unrealfile-stacktree-data.php.txt |
| 2026-10-23T14:43:58.8069646Z | verify-ue1-original-unrealfile-external-schemas.php | PASS | 428 | catalog\compliance\evidence\o06\20261023T144358063Z-1c536d61-DEV-verify-ue1-original-unrealfile-external-schemas.php.txt |
| 2026-10-23T14:43:58.0632641Z | verify-ue1-original-unrealfile-external-schemas.php | STARTED |  | catalog\compliance\evidence\o06\20261023T144358063Z-1c536d61-DEV-verify-ue1-original-unrealfile-external-schemas.php.txt |
| 2026-10-23T14:43:57.7297485Z | verify-ue1-original-unrealfile-inheritance.php | PASS | 136 | catalog\compliance\evidence\o06\20261023T144357279Z-96aeef14-DEV-verify-ue1-original-unrealfile-inheritance.php.txt |
| 2026-10-23T14:43:57.2800161Z | verify-ue1-original-unrealfile-inheritance.php | STARTED |  | catalog\compliance\evidence\o06\20261023T144357279Z-96aeef14-DEV-verify-ue1-original-unrealfile-inheritance.php.txt |
| 2026-10-23T14:43:56.9449713Z | verify-ue1-original-unrealfile-properties.php | PASS | 119 | catalog\compliance\evidence\o06\20261023T144356511Z-e0df20d1-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:43:56.5120576Z | verify-ue1-original-unrealfile-properties.php | STARTED |  | catalog\compliance\evidence\o06\20261023T144356511Z-e0df20d1-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:43:56.1556692Z | verify-ue1-original-unrealfile-class-schemas.php | PASS | 122 | catalog\compliance\evidence\o06\20261023T144355717Z-c352a608-DEV-verify-ue1-original-unrealfile-class-schemas.php.txt |
| 2026-10-23T14:43:55.7174406Z | verify-ue1-original-unrealfile-class-schemas.php | STARTED |  | catalog\compliance\evidence\o06\20261023T144355717Z-c352a608-DEV-verify-ue1-original-unrealfile-class-schemas.php.txt |
| 2026-10-23T14:43:55.3869674Z | verify-ue1-o06-g2-rotation-bin-lineage.php | PASS | 105 | catalog\compliance\evidence\o06\20261023T144354933Z-e1a6d651-DEV-verify-ue1-o06-g2-rotation-bin-lineage.php.txt |
| 2026-10-23T14:43:54.9402017Z | verify-ue1-o06-g2-rotation-bin-lineage.php | STARTED |  | catalog\compliance\evidence\o06\20261023T144354933Z-e1a6d651-DEV-verify-ue1-o06-g2-rotation-bin-lineage.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
