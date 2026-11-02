# Fixed test workflow for UE1, UE2, UE3, UE4, UE5

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
