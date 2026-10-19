# UE5.8.3 fixed primary reconciliation plan

Baseline: c4a6c0a2 (2026-10-19). Frozen primary denominator: 11 areas from UE583-SOURCE-DENOMINATOR.json. No new B workstreams. Start at P01; advance only when production branches are matched to pinned Epic code and corrections are deployed. Evidence is recorded per area, not inferred from passing tests.

| Priority | Unit | Scope | State |
|---|---|---|---|
| P01 | SRC-001 | Classic summary, versions and admission | verified within supported scope — native 140-package field differential |
| P02 | SRC-002 | Classic name/import/export/dependency tables | VERIFIED: 140 native packages, 44,840 classic table fields, 2,832 DependsMap rows / 9,069 exact references; S001 RF_Load corrected |
| P03 | SRC-003 | Zen header and versioning | 100 real unversioned Zen packages / 6,546 native fields plus one versioned source-conforming fixture / 38 native fields match; optional runtime branch remains |
| P04 | SRC-004 | Zen import/export/dependencies | Real unversioned and one native versioned import/export/dependency comparison passed; optional-segment mount/provider outcomes remain |
| P05 | SRC-005 | Script objects and identities | reviewed; existing verified status reaffirmed |
| P06 | SRC-006 | Package store backend arbitration | reviewed; existing verified status reaffirmed |
| P07 | SRC-007 | File package store/container headers | reviewed; B006/foundation passed; mount/recovery remains open |
| P08 | SRC-008 | IoStore TOC and chunk identities | VERIFIED within TOC wire-format scope: 360,091 native real fields + 11 positive/5 negative native branch fixtures |
| P09 | SRC-009 | IoStore reconstruction/crypto/compression | reviewed; prior 27,240 Epic byte differential verified |
| P10 | SRC-010 | Modern PAK index/directory | reviewed; native PAK11 independent differential verified |
| P11 | SRC-011 | Modern PAK payload/block decoding | reviewed; native PAK11 differential and acceptance verified |

## Rule for each batch

1. Read the applicable pinned Epic parsing branches, including guards, version switches, and return/error paths.
2. Trace the matching UnrealDB production dispatch and implementation.
3. Within active Pxx: correct source mismatches, record exact before/after and targeted acceptance.
4. Finding outside active Pxx: append to UE583-SECONDARY-FINDINGS.md and **do not switch area** except immediate containment of destructive risk.
5. Run bounded tests; commit to main; deploy to PRD; verify revision and recorded evidence.
6. Close Pxx only after every applicable branch is enumerated as matched/corrected/excluded and native differential evidence is adequate. Never close based only on hashes or roundtrips.

After P11, resolve secondary findings in order, then accept the **existing** 16 workflows in RECOVERY.json and perform one final certification verification. No UE4.27.2 before UE5 decision.

## Checkpoint

P01 source branch matrix completed for supported classic summary layouts. Epic-native UnrealPackageTool differential passed 140 real packages / 5,279 fields; existing negative reader contract passed. P02 primary pass: 140 native packages / 44,840 name-import-export field comparisons; 50 native DependsMap packages / 253 export rows / 353 dependency refs matched length distributions. DependsMap now decoded and V5-persisted. SRC-002 remains partial because S001 is deferred after P11, as requested. P03 primary pass: Epic variable-header reader confined to HeaderSize, corrected in production and Zen regressions passed. SRC-003 remains partial for an independent Epic decoded Zen oracle; UnrealPackageTool does not decode extracted Zen .uheader. P04 primary pass: negative FirstEntryIndex dependency skip aligned with Epic, Zen and B004/B012 acceptances passed. SRC-004 remains partial for independent native decoded Zen import/export/dependency semantics. P05 primary pass: native script hashing, ScriptObjects decoding and live-vs-cooked boundary reviewed; acceptance checks passed; existing SRC-005 remains verified. P06 source priority, terminal/fallback and redirect branches passed source oracle, arbitration, callers and B005 acceptance. SRC-006 remains verified. P07 B006 source, operation acceptance and IoStore foundation all passed. SRC-007 remains partial until native mount/recovery and future-version header semantics. Primary P01–P11 review complete. P08 field-level native oracle remains a certification gap; SRC-009/010/011 existing independent differential evidence retained. Active: secondary findings in UE583-SECONDARY-FINDINGS.md, beginning S001 Epic RF_Load flags. See UE583-P08-P11-IOSTORE-PAK-PRIMARY-REVIEW.md.
