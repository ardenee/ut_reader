# UE5.8.3 fixed primary reconciliation plan

Baseline: c4a6c0a2 (2026-10-19). Frozen primary denominator: 11 areas from UE583-SOURCE-DENOMINATOR.json. No new B workstreams. Start at P01; advance only when production branches are matched to pinned Epic code and corrections are deployed. Evidence is recorded per area, not inferred from passing tests.

| Priority | Unit | Scope | State |
|---|---|---|---|
| P01 | SRC-001 | Classic summary, versions and admission | verified within supported scope — native 140-package field differential |
| P02 | SRC-002 | Classic name/import/export/dependency tables | IN PROGRESS |
| P03 | SRC-003 | Zen header and versioning | queued |
| P04 | SRC-004 | Zen import/export/dependencies | queued |
| P05 | SRC-005 | Script objects and identities | queued |
| P06 | SRC-006 | Package store backend arbitration | queued |
| P07 | SRC-007 | File package store/container headers | queued |
| P08 | SRC-008 | IoStore TOC and chunk identities | queued |
| P09 | SRC-009 | IoStore reconstruction/crypto/compression | queued |
| P10 | SRC-010 | Modern PAK index/directory | queued |
| P11 | SRC-011 | Modern PAK payload/block decoding | queued |

## Rule for each batch

1. Read the applicable pinned Epic parsing branches, including guards, version switches, and return/error paths.
2. Trace the matching UnrealDB production dispatch and implementation.
3. Within active Pxx: correct source mismatches, record exact before/after and targeted acceptance.
4. Finding outside active Pxx: append to UE583-SECONDARY-FINDINGS.md and **do not switch area** except immediate containment of destructive risk.
5. Run bounded tests; commit to main; deploy to PRD; verify revision and recorded evidence.
6. Close Pxx only after every applicable branch is enumerated as matched/corrected/excluded and native differential evidence is adequate. Never close based only on hashes or roundtrips.

After P11, resolve secondary findings in order, then accept the **existing** 16 workflows in RECOVERY.json and perform one final certification verification. No UE4.27.2 before UE5 decision.

## Checkpoint

P01 source branch matrix completed for supported classic summary layouts. Epic-native UnrealPackageTool differential passed 140 real packages / 5,279 fields; existing negative reader contract passed. Active: P02 classic name/import/export/dependency tables. S001 remains deferred until after P11 by the agreed secondary-queue rule.
