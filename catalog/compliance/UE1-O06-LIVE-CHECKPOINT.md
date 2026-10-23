# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g2-original-1997-rotation-bins`
- **Stage:** isolated-feature-verified
- **Last completed action:** Production May/July per-object and UProperties rotation archive widths corrected and isolated: 30/30 focused DEV source tests PASS; 5/5 affected current DEV PASS with 4 reused or rerun selectively. Feature commit b8ed0b36 pushed on o06/g2-rotation-bins. Shared main concurrently handles a separate bounds contract; its files and journal remain untouched by isolated test runs.
- **Resume at:** After shared-main bounds commit is complete, cherry-pick only b8ed0b36; integrate exact append-only test events and contract test plan; run affected tests only if source inputs changed; deploy three PHP readers to PRD with SHA-256, never PHP tests on PRD. G2 remains open.
- **Active changed at:** 2026-10-23T14:41:12+00:00
- **Latest DEV test states:** PASS 98; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-23T14:37:51.4526461Z | verify-ue1-original-unrealfile-properties.php | PASS | 116 | catalog\compliance\evidence\o06\20261023T143750983Z-3f77032d-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:37:50.9912985Z | verify-ue1-original-unrealfile-properties.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143750983Z-3f77032d-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:37:23.2192639Z | verify-ue1-original-unrealfile-properties.php | FAIL | 74 | catalog\compliance\evidence\o06\20261023T143722787Z-1fafb4be-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:37:22.7960200Z | verify-ue1-original-unrealfile-properties.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143722787Z-1fafb4be-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:36:51.7723280Z | verify-ue1-original-unrealfile-properties.php | FAIL | 162 | catalog\compliance\evidence\o06\20261023T143651283Z-1f81fb46-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:36:51.2833600Z | verify-ue1-original-unrealfile-properties.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143651283Z-1f81fb46-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:36:50.9330126Z | verify-ue1-original-unrealfile-class-schemas.php | PASS | 177 | catalog\compliance\evidence\o06\20261023T143650400Z-e1825948-DEV-verify-ue1-original-unrealfile-class-schemas.php.txt |
| 2026-10-23T14:36:50.4085204Z | verify-ue1-original-unrealfile-class-schemas.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143650400Z-e1825948-DEV-verify-ue1-original-unrealfile-class-schemas.php.txt |
| 2026-10-23T14:35:17.2905708Z | verify-ue1-original-unrealfile-external-schemas.php | PASS | 492 | catalog\compliance\evidence\o06\20261023T143516454Z-2953c17d-DEV-verify-ue1-original-unrealfile-external-schemas.php.txt |
| 2026-10-23T14:35:16.4544384Z | verify-ue1-original-unrealfile-external-schemas.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143516454Z-2953c17d-DEV-verify-ue1-original-unrealfile-external-schemas.php.txt |
| 2026-10-23T14:35:16.1066019Z | verify-ue1-original-unrealfile-inheritance.php | PASS | 205 | catalog\compliance\evidence\o06\20261023T143515601Z-112fb7b4-DEV-verify-ue1-original-unrealfile-inheritance.php.txt |
| 2026-10-23T14:35:15.6010204Z | verify-ue1-original-unrealfile-inheritance.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143515601Z-112fb7b4-DEV-verify-ue1-original-unrealfile-inheritance.php.txt |
| 2026-10-23T14:35:15.2388062Z | verify-ue1-original-unrealfile-properties.php | FAIL | 74 | catalog\compliance\evidence\o06\20261023T143514861Z-d1fc19cc-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:35:14.8628805Z | verify-ue1-original-unrealfile-properties.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143514861Z-d1fc19cc-DEV-verify-ue1-original-unrealfile-properties.php.txt |
| 2026-10-23T14:35:14.5131475Z | verify-ue1-original-unrealfile-class-schemas.php | FAIL | 77 | catalog\compliance\evidence\o06\20261023T143514088Z-9e1d48a9-DEV-verify-ue1-original-unrealfile-class-schemas.php.txt |
| 2026-10-23T14:35:14.0958904Z | verify-ue1-original-unrealfile-class-schemas.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143514088Z-9e1d48a9-DEV-verify-ue1-original-unrealfile-class-schemas.php.txt |
| 2026-10-23T14:34:53.6257002Z | verify-ue1-o06-g2-rotation-bin-lineage.php | PASS | 157 | catalog\compliance\evidence\o06\20261023T143453084Z-48cd0159-DEV-verify-ue1-o06-g2-rotation-bin-lineage.php.txt |
| 2026-10-23T14:34:53.0913640Z | verify-ue1-o06-g2-rotation-bin-lineage.php | STARTED |  | catalog\compliance\evidence\o06\20261023T143453084Z-48cd0159-DEV-verify-ue1-o06-g2-rotation-bin-lineage.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
