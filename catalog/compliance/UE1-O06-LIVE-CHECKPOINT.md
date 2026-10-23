# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g2-original-1997-rotation-bins`
- **Stage:** ready-to-release
- **Last completed action:** Corrected May original v22/v23 per-object UClass::SerializeBin FRotation 6-byte WORD and UProperties separate bin versus July 12-byte signed INT; verified real Epic May/July source, 30/30 focus, 5/5 DEV affected PASS. Three production files byte-identical to independently tested isolated git branch; no unrelated reader changes. G2 gate remains open.
- **Resume at:** Commit/push verified G2 fix and evidence; deploy exactly 3 source PHP readers DEV to PRD with per-file SHA256. Never run PRD PHP tests; record commit and release in checkpoint.
- **Active changed at:** 2026-10-23T14:48:18+00:00
- **Latest DEV test states:** PASS 99; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
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
| 2026-10-23T14:33:01.6518307Z | verify-ue1-original-unrealfile-mesh-records.php | PASS | 263 | catalog\compliance\evidence\o06\20261023T143301027Z-c841b3a1-DEV-verify-ue1-original-unrealfile-mesh-records.php.txt |
| 2026-10-23T14:33:01.0342870Z | verify-ue1-original-unrealfile-mesh-records.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143301027Z-c841b3a1-DEV-verify-ue1-original-unrealfile-mesh-records.php.txt |
| 2026-10-23T14:32:30.4298889Z | verify-ue1-original-unrealfile-mesh-records.php | FAIL | 484 | catalog\compliance\evidence\o06\20261023T143229591Z-b70aee09-DEV-verify-ue1-original-unrealfile-mesh-records.php.txt |
| 2026-10-23T14:32:29.5983117Z | verify-ue1-original-unrealfile-mesh-records.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143229591Z-b70aee09-DEV-verify-ue1-original-unrealfile-mesh-records.php.txt |
| 2026-10-23T14:31:22.8409900Z | verify-ue1-original-unrealfile-database-records.php | PASS | 159 | catalog\compliance\evidence\o06\20261023T143122366Z-256b59cd-DEV-verify-ue1-original-unrealfile-database-records.php.txt |
| 2026-10-23T14:31:22.3667154Z | verify-ue1-original-unrealfile-database-records.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143122366Z-256b59cd-DEV-verify-ue1-original-unrealfile-database-records.php.txt |
| 2026-10-23T14:31:22.0309286Z | verify-ue1-o06-1997-bounds-raw-byte.php | PASS | 81 | catalog\compliance\evidence\o06\20261023T143121568Z-06dea81c-DEV-verify-ue1-o06-1997-bounds-raw-byte.php.txt |
| 2026-10-23T14:31:21.5757147Z | verify-ue1-o06-1997-bounds-raw-byte.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143121568Z-06dea81c-DEV-verify-ue1-o06-1997-bounds-raw-byte.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
