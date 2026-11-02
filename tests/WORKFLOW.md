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

### UE1 source loader and inspector correction (2026-11-02)

The original UT99 v432 Core summary high 16-bit licensee field is not a unique Unreal v227 game-loader identity. The UT99 PRD corpus contains 52 version-69/licensee-227 original packages. Explicit UT99/Unreal source selection is authoritative; no game selector is inferred from that field alone. The genuine UE1 CLI full inspector no longer overwrites raw package-header bytes with an array, and the recursive mode includes package files instead of UZ wrappers only. Build overlays 227i/227k/469f are retained. No dependency SQL writes or new native engine runs. See tests/evidence/ue1/ue1-source-loader-provenance-cli-20261102.json.

### UE1 dispatch F01/F02 PASS on 2026-11-02

The UE1 dispatch contract is PASS: five source-original PRD files through real UEDB5 source factory, verified import inspector and unverified staging gave exact source-selected UE1 header/name/import/export parity. The Unreal 227 decoder gate now applies identically to all three reader entrypoints and never to UT99. Provisional UE2/UE3 legacy version hints do not override UE1 selected games; UE2-owned UT2004 legacy adapter remains separate. Unknown game/slug and bad package magic fail closed. Pinned original Epic v1.200/v227/v432 source and retained five-build native provenance. No native runtime reruns or PRD writes; VerifyImport and dependency SQL remain separate open contracts. Evidence: tests/evidence/ue1/ue1-dispatch-full-real-production-20261102.json.

### UE1 tables F03/F04/F05/F06 PASS (2026-11-02)

From original PRD verified files (Unreal v54/v60/v61/v69 and UT99 v68/v69), 35,257 full current UE1 summary/name/import/export rows exactly matched existing published table rows. A separate independent Python raw binary decoder reproduced all 17,700 name strings/flags, 818 import records and 16,729 export records with original serialized offsets, fields and 560,719 table bytes. Pinned local Epic v1.200/v432/v227 source headers and previously accepted genuine UE1 reader isolation. Seven historical source-policy labels differ; they are provenance metadata awaiting resync, not table-byte mismatches. PRD database and original files were never modified; native runtime VerifyImport remains a separate contract. Evidence: tests/evidence/ue1/ue1-tables-original-source-byte-parity-20261102.json.

### UE1 VerifyImport production source gate consolidated (2026-11-02; contract remains BLOCKED)

The exact source-authorized Unreal v1.200/UT99 v1.400 import resolution gate is now owned by UE1-only Uedb5Ue1VerifyImportSourceProfile and reused by four actual production paths: Pass2 metadata resolver, parsed package metadata snapshot, cross-game examination, and legacy PdoDependencyResolver. UT99 pre-v60 is source-unattributed, not retail v1400. 21 source version/licensee cases across four callers and eight original MD5-matched PRD package snapshots pass with policy laundering rejected. All UE1 later v69 and foreign-licensee revisions remain unresolved unless a separate original native implementation and runtime mount context is proven. Existing original v432 A/B native fixture source-only direct lookup remains an insufficient general oracle; this is not a PASS for VerifyImport and no PRD dependency resync was performed. Evidence: tests/evidence/ue1/ue1-verifyimport-single-source-policy-20261102.json.
