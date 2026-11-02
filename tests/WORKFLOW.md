# Fixed test workflow for UE1, UE2, UE3, UE4, UE5

**PRD holds the original production database and file corpus.** DEV does not. Use SELECT-only/READ-ONLY transactions on PRD MySQL 8.4.11 over 192.168.0.55:3306, and read PRD original package and UEDB5 files via UNC //192.168.0.55/c$/Apache24/htdocs/unrealdb/catalog/storage from the DEV PHP CLI. The Z: PowerShell mapping does not work in PHP CLI for those files. Do not write PRD SQL or mutate original files during parity tests. Change code on DEV; deploy only verified changes. No original files or credentials in Git.

1. Select a single behaviour/engine row from contracts.json. Trace its real web, job, service, reader and persistence call chain. Do not use static filename guesses as verified reachability.
2. Find the exact selected Epic engine and game/source revision functions and archive branches, including version and licensee, and record what UnrealDB exposes. Separate serialized bytes from runtime-only memory effects.
3. Consult the accepted 2026 legacy evidence. If the current code and source-selected behaviour are already demonstrated, cite that proof and STOP. Do not rerun it.
4. Only propose a test when there is a specific observable user risk and proof gap. Required record: source function, file/build, original expected result, UnrealDB actual result, assertion, and decisive stopping condition.
5. Repair the smallest existing production path when a mismatch exists; perform one bounded original-source/native comparison only if existing evidence is insufficient.
6. Compare end to end: original file bytes → decoded name/object/reference → selected provider and VerifyImport → UEDB5 stored dependency edges/counts → user-facing result.
7. Update the ONE row (PASS / FAIL / BLOCKED / SOURCE_NA) with SHA-pinned proof. No extra tests simply because tools exist. No invented success if mounted provider context is unknown.
8. Deploy the changed implementation only after affected rows pass, protected source/PRD preflight, and DEV/PRD SHA checks. Keep only accepted final evidence.

A discovered behaviour outside the 24 frozen contracts requires an explicit user-risk rationale and approval to add. Never increase accepted test totals by repeatedly testing identical source code. Batch independent unverified requirements; retain existing engine certifications.

Transient tests: C:\Temp\UnrealDB\<engine>\<contract>\<run>. Canonical accepted proofs: tests/evidence/<engine>/<contract>. Never create new copies of complete engine source or massive cooked fixtures without a specific indispensable oracle and cleanup rule.

Performance is measured by mandatory *unverified behaviour remaining*, defects corrected and production parity—not the quantity of tests or log files.

## Reader architecture

The UE1, UE2, UE3, UE4 and UE5 implementations now own separate concrete package parsers. UE1 and UE2 each own their byte IO, package-summary and table parsing, and UEDB5 table snapshot serializers. No engine-specific reader or byte-stream class is shared. Generic catalog UEDB5 dependencies and provider resolution remain shared; edits to those shared services require explicit cross-engine impact review. Use python tests/tools/impact.py --path <changed source> to identify affected operation cases.

## UE1 source-correct dependency publication, 2026-11-02

The current UE1 publication marker is uedb5-dependency-pass-ue1-mount-v14. UE2 through UE5 retain uedb5-dependency-pass-v13. The old 85091 UE1 PRD rows are historical, not source-current; current file readers reject old UE1 dependency outcomes and the admin missing-dependency page withholds their stale counts. Original native mount contexts are accepted only in classic_runtime_context_by_game_and_file scoped to the exact game and consumer with an explicit evidence_source. If native mount context is missing, source-selected physical providers remain unresolved. An isolated production SQL publisher run on three genuine PRD consumer snapshots produced 442 accurate dependency edges and no fabricated missing rows. PRD SQL remains read-only, and no mass dependency data rebuild was performed. See tests/evidence/ue1/prd-source-correct-dependency-publication-20261102.json.
