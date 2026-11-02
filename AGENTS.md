# UnrealDB development and Epic parity contract

## One objective
UnrealDB must handle Unreal *files and exposed dependency results* as the correctly selected Epic engine/game loader does: version/source selection, archive data, names, imports/exports, object identity, original VerifyImport and package search order, stored dependency graph, and user-facing outputs. Do not reproduce unrelated rendering, gameplay, physics or memory-only operations that have no file/catalogue effect.

Epic source is authoritative at L:\Source (UE1–UE5), with applicable game source layered on the engine source. The package version alone is not evidence of the correct game: Unreal v1.227 and UT99 v432 can share v69; v469f must be explicitly identified. Never borrow a different game's reader as fallback, and never invent a mounted package when runtime search order is unavailable. Report UNKNOWN/AMBIGUOUS rather than a false missing/resolved dependency.

## Current testing authority (2026-11-02 reset)
- **tests/README.md** — only current workflow authority.
- **tests/contracts.json** — 24 risk categories (not tests), 32 concrete implementation-path cases with one status per case. NOT_REVIEWED is a planning state for the new end-to-end contract, not rejection of prior accepted proofs.
- **tests/code-paths.json** — all tracked production PHP/web/operational paths and hashes, candidate mapping only; trace actual calls before accepting.
- **tests/ARCHITECTURE.md** — operational pipeline, dependency and persistence boundaries.
- **tests/WORKFLOW.md** — single evidence result / no rerun rule.
- **archive/testing-legacy-2026-11-02/** — frozen historical test scripts, notes, native evidence and original SHA mapping. Historic scripts are not live and may have previous relative path references. To reproduce them, use the frozen commit in a disposable worktree.
- **Reader architecture:** UE1, UE2, UE3, UE4, UE5 own independent package parser implementations. UE1 and UE2 do not share any parser, byte stream, table interface or table snapshot serializer. UT2004 legacy-format compatibility is UE2-owned. Original byte/table and game-specific UEDB5 snapshot preservation is pinned at tests/evidence/reader-isolation-20261102.json. Shared UEDB5 dependency/provider logic still requires cross-engine impact review.\n- **Graphify** at http://127.0.0.1:8765 on DEV is an optional read-only view derived from tests/contracts.json, not an independent acceptance authority.

Do not create another test register, unrelated "closure" spreadsheets, parallel status dashboard, synthetic pass counter or extra test IDs. To propose a new category, state user-visible risk, precise Epic operation and why none of the 24 categories covers it, and obtain approval first.

## Workflow
1. Select one NOT_REVIEWED/FAIL/BLOCKED contract/engine; trace actual production entrypoint→source-selected reader→provider/linker→UEDB5 persistence→user view. Identify exact Epic function and version branches.
2. **Reuse old accepted original native/source evidence first.** Check source and current product hashes. If this exactly closes the contract, link it and STOP; do not rerun.
3. Otherwise require a specific test purpose: original source function, genuine input, original expected output, UnrealDB actual output, pass criterion and stop condition. If any are unknown, investigate *only the missing prerequisite*, not extra methods.
4. Fix the smallest production module and verify one genuinely new differential (plus directly affected regression), in batches across different needed contracts. Explicit source-unavailable is BLOCKED, never PASS. SOURCE_NA is only source-proven inapplicability.
5. Record one result in tests/contracts.json, link final SHA-pinned original evidence, and stop the test. No "continue" reruns of previously passing contracts with unchanged transitive inputs.
6. Deploy only verified changed source from DEV to PRD with protected config/storage/Git exclusions and exact SHA checks. Do not execute PHP acceptance tests or change Epic source on PRD.

Temporary work: C:\Temp\UnrealDB\<engine>\<contract>\<run>. Unique durable new proof only under tests/evidence/<engine>/<contract>. Limit copied engine builds, instrumented DLLs and cooked projects to a specified necessary oracle and post-acceptance cleanup decision. Never change existing accepted native evidence or original source packages.

## Environments
- DEV game-amd (192.168.0.51): C:\Users\arden\source\repos\ut_reader. Docker available, not needed for file parity.
- PRD Lelly-pc (192.168.0.55): C:\Apache24\htdocs\unrealdb; from DEV Z:\Apache24\htdocs\unrealdb.
- Never overwrite catalog/config.php, catalog/storage, .git, or .github when synchronizing.
- Local engine and game source: L:\Source\Engine\UE1..UE5 and L:\Source\Games. No uploads of private Epic source to third-party services.
- Valid production jobs, migrations, repairs and workers remain in catalog/bin. Old verification-only scripts are in archive.

## Historical, not current contract status
UE1 G3 approved 80-test programme closed 79 PASS, T050 one exact SOURCE_NA and no blockers; eight selected UE1 UT99 v432 G5 source methods FULL. UE4.27.2 and UE5.8.3 native parity evidence previously accepted for their specific scopes. Do not call every engine/file/dependency scenario fully certified until the **new** end-to-end contract rows are individually resolved. Archive preserves the original frozen results and source revision IDs.
