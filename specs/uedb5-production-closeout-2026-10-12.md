# UEDB5 production closeout evidence — 2026-10-12

## Scope and evidence level

This is a **read-only bounded closeout**. It does not rerun every original package parse, every container hash, or an all-game behavioural parity sweep. Registration/status counts are database facts, **not** independent proof that every dependency outcome or source-policy decision is correct.

## Production registration and retirement (observed)

Read-only queries against production `hwmvlite_ut_reader_catalog` returned:

| Game | Verified | V5 registered | Status validated |
| --- | ---: | ---: | ---: |
| Unreal | 10,788 | 10,788 | 10,788 |
| Unreal II | 1,217 | 1,217 | 1,217 |
| Unreal Tournament | 74,303 | 74,303 | 74,303 |
| Unreal Tournament 2003 | 5,082 | 5,082 | 5,082 |
| Unreal Tournament 2004 | 72,880 | 72,880 | 72,880 |
| Unreal Tournament 3 | 6,722 | 6,722 | 6,722 |
| Unreal Tournament 4 | 64,247 | 64,247 | 64,247 |
| **Total** | **235,239** | **235,239** | **235,239** |

All 235,239 V5 registrations are format 5. The following retired SQL tables were checked and **none exists**: `ue_file_metadata`, `ue_names`, `ue_imports`, `ue_exports`, `ue_dependencies`, `ue_search_documents`, `ue_terms`, `ue_name_lookup`, `ue_export_lookup`, `ue_export_path_lookup`, `ue_legacy_export_identity_lookup`, `ue_dependency_links`, `ue_dependency_identity_lookup`.

The source-only `verify-uedb5-cutover-ready.php` returned `ok=true`, all four source checks passing, `database_checked=false`. Its `cutover_ready=false` **is expected without `--database`**; this is a pre-cutover gate, not a post-cutover verifier.

Targeted production reader/writer, cutover contract, Zen package, Zen dependency and V5 SQL projection contract tests all passed; these fixtures do not establish all-game parity or live Zen ingestion.

The live `PdoPackageTablePageQuery` reads V5-only `Uedb5RuntimeMetadataReader`. No active V4 reader or V4 fallback has been identified in the inspected `catalog/src` and `catalog/lib` runtime PHP. There remain obsolete V4-related comments, staging isolation contracts, and historical migration/test scripts. Their presence is **not** proof of a live V4 fallback.

## Not closed by these checks

1. **File retirement:** a complete physical enumeration of `catalog/storage/metadata/**/*.uedb4` has not finished. Do not claim zero files or delete any until an authoritative count and separate safety gate are recorded.
2. **Per-game source compliance/parity:** evidence of an all-green final, source-checked UE1/UE2/UE3/UE4/UE5 behavioural parity report for each engine/game has not been established. Status `validated` is insufficient.
3. **UE5 Zen/IoStore:** the isolated reader, container snapshot and resolver exist; production `.utoc/.ucas` ingestion, AssetRegistry build/cook data, and complete live end-to-end tests have not been proven. The seven production games currently enumerated do not demonstrate UE5 Zen production coverage.
4. **V5 SQL sizing:** `ue_uedb5_name_candidates` (~83.53 GiB) plus `ue_uedb5_object_candidates` (~54.53 GiB) account for ~138.06 GiB. Their indexes alone account for ~72.15 GiB. Both participate in live search; no safe removal/change is justified without query-plan, parity and measured replacement tests.
5. **Final post-cutover verifier:** implement a **bounded post-cutover** audit separate from `verify-uedb5-cutover-ready.php`; its existing deep database mode reparses the entire catalogue and is not part of this bounded closeout.

## Architecture and remaining work

The authoritative chain must remain **original Unreal bytes → source-aligned reader → UEDB5 container → minimal SQL lookup projections**. Do not migrate by translating V4 metadata. Production is registered in V5, but **source-compliance closure, physical V4 file retirement, UE5 Zen scope, projection optimisation and full post-cutover proof remain open**.

The bounded post-cutover command `catalog/bin/verify-uedb5-post-cutover-bounded.php` was added and run on production: **five checks passed**, `ok=true`; it intentionally does not claim physical-file retirement, exhaustive hashes or game parity.

No live SQL changes, metadata reparse, file deletion, or global parity rescan was performed by this audit.

## Immediate gates for final signoff

- Enumerate only known metadata container directories to prove `.uedb4` count is zero; preserve unexpected files for investigation.
- Obtain existing per-game final parity/source evidence, or perform narrowly scoped audits for gaps.
- Bounded post-cutover SQL/source checks now pass. Add representative container/hash sample checks before declaring final integrity closeout.
- Establish live Zen/IoStore acceptance criteria distinctly from classic UE5 support.
- Prove lossless lookup results and index usage before changing the two massive candidate projections.
- Retire obsolete staging/legacy code only when a dependency/reference audit establishes it is unused; then update the other historical specification sections.

## Closeout continuation — physical inventory, hash verification, parity and SQL indexes

**Physical file inventory:** on PRD, targeted canonical directories under `catalog/storage/metadata/{game}/{shard}/` contain **235,239 UEDB4 files, 235,239 UEDB5 files and 27.833 GiB of UEDB4 bytes**. Per-game V4 file counts and GiB: Unreal 10,788 / 1.323; Unreal II 1,217 / 0.079; UT99 74,303 / 8.307; UT2003 5,082 / 0.665; UT2004 72,880 / 13.908; UT3 6,722 / 3.195; UT4 64,247 / 0.356. Every legacy filename has a same-directory V5 filename (0 unmatched). This is *not* full V5 payload validation or backup verification. No files were deleted. Repeat using the read-only `catalog/bin/audit-uedb4-physical-retirement.php`.

**Critical runtime verifier defect fixed:** `Uedb5RuntimeMetadataReader::verify()` was converting SQL's raw `BINARY(32)` SHA-256 to hexadecimal text before passing it to `Uedb5MetadataContainer::verifyFile()`, which compares raw SHA-256 bytes. All 14 sampled V5 files initially failed this API despite a raw-digest and size comparison on file 110902 matching SQL exactly. Removing `bin2hex()` fixed the API. Afterwards, full container verification passed for **14/14 sampled V5 files**, comprising lowest/highest verified file ID from each of the seven games. The bounded post-cutover command now runs these samples in the deployment's own configured storage directory: **six checks passed on PRD**. Running the same CLI script from DEV against the PRD database incorrectly probes DEV storage; run the production copy on PRD. These samples are not exhaustive proof of 235,239 payloads.

**Parity evidence:** `C:\Temp\ut4-parity-final.json` on PRD records UT4 64,247 validated and dependency-complete files; report `all_complete=true`, with 0 unexpected provider-selection and object-coverage differences. The report is dated 2026-10-07. Remaining games have some `C:\Temp\4j-*-summary.json` read-only impact/preflight summaries, but these are not final whole-game behavioural parity reports. Do not mark those other games as fully audited based on 4J preflights.

**UE5 Zen/IoStore:** Zen package/serialization and dependency-resolution contract suites pass. Production's seven verified game profiles currently contain no standalone UE5 profile, so the checks do **not** prove real-world `.utoc/.ucas` ingestion in production, and AssetRegistry build/cook dependency ingestion remains a distinct unclosed requirement.

**SQL projection baseline:** production statistics: name candidates 320,561,132 rows, ~83.53 GiB (39.17 indexes); object candidates 355,295,715 rows, ~54.53 GiB (32.98 indexes). Read-only `SHOW INDEX` found the name-candidate primary and name-lookup secondary, and object-candidate primary, object-name and public-export secondary. `EXPLAIN` confirms current exact-name queries use `idx_ue_uedb5_name_lookup`, object-name queries use `idx_ue_uedb5_object_name`; file-local object queries can use `idx_ue_uedb5_public_export` (including filesort for ordering). These are live lookup accelerators, **not safe blind drop candidates**. Next optimisation requires designing and benchmarking a substantially narrower lossless projection, replaying all search/VerifyImport/provider-resolution contracts against it, then an explicitly planned storage migration. No index/table changes were made.

**Legacy tooling cleanup:** active source/library PHP references to V4 are chiefly old comments, negative/fallback guards and staging-era contracts; no live V4 parser fallback was identified by the audited V5 source checks. Migration-related scripts remain in `catalog/bin`. Do not delete repair or migration tooling until caller/ref inventory proves that current maintenance and recovery no longer invoke it. Two unrelated pre-existing untracked verification files remain untouched.

**Decision / remaining signoff:** preserve the 27.833 GiB of physical UEDB4 files until V5 integrity beyond small samples and a usable recovery copy are established; record no removal success yet. Epic-source parity closure for the other six games, production Zen acceptance, candidate projection optimisation, and removal of obsolete tooling remain work items. None requires blindly rescanning original package bytes.

## Continuation: 2026-10-12 — targeted source parity and recovery boundaries

- Added `catalog/bin/verify-uedb5-post-cutover-source-samples.php`. It runs the **read-only** `Uedb5MigrationValidator::validate()` on the lowest and highest currently verified file ID in each game, without modifying migration-status rows. Live PRD result: **14/14 ready=true, source_validation_mode=fresh_verified_source**, spanning seven games. This verifies fresh Unreal-source parsing, current V5 container data and SQL projection consistency *for those 14 files*, **not** an exhaustive per-game parity assertion or a new complete catalogue scan.
- Current production `ue_uedb5_files.package_family` grouped counts: `classic-linkerload=170,992`, `ue4-classic-package=64,247`, **Zen/IoStore=0**. Current isolated Zen reader/resolver contract tests pass, but live `.utoc/.ucas` ingestion has **not** been established. Treat production Zen integration as an independent acceptance requirement.
- Current raw V5 registration digest audit found **zero NULL/wrong-length SHA-256 values**. This is not equivalent to checking the corresponding on-disk hash for all registered files.
- Live backup inventory found eight `catalog/storage/game-backups` directories with July 2026 names; the sampled UT4 backup README reports **2026-07-21** and 13,354 copied entries. These historical game backups do not establish a contemporaneous, complete October 2026 V5 recovery copy. **Do not delete** the 235,239 V4 containers until adequate independently restorable V5 backups and comprehensive V5 integrity verification are proven.
- Relevant current tests pass: `verify-ue1-verify-import-profile-contract.php`, `verify-ue2-profile-verify-import-contract.php`, `verify-uedb5-unreal-classic-dependency-resolution.php`, `verify-uedb5-ut3-persistence.php`, `verify-uedb5-ut4-persistence.php`, `verify-uedb5-ut99-persistence.php`, `verify-uedb5-dependency-rebuilder.php`, `verify-uedb5-dependency-projection-publisher.php`, `verify-uedb5-parity-targeted-search.php`, and `verify-uedb5-ue5-iostore-foundation.php`.
- Two **legacy** contracts fail because they assert absent retired files/SQL architecture: `verify-ue3-verify-import-contract.php` still requires `backfill-ue3-export-identities.php` and V4-era `object_term_id` lookup; `verify-ue4-dependency-matching-contract.php` expects removed `audit-ue4-missing-verifyimport.php` (36 other assertions pass). These are *not* validated V5 source-parity failures, but are still failing tests. Before removal, map each unique semantic assertion to its current V5 source-backed contract; retain or port tests that exercise relevant semantics. Do not simply suppress them.
- Live `EXPLAIN` confirmed the name-candidate and object-name composite indexes serve real lookups. No indexes or tables changed. Further savings require a candidate-schema prototype, objective query-plan metrics, and no-loss search/provider/object-parity checks; the earlier 138.06-GiB two-table footprint cannot be safely removed by dropping an active secondary index.

**Closeout remains partial:** physical V4 retirement is held for verified recovery and comprehensive V5 hash evidence; full all-game source parity and Zen production acceptance remain unproved; SQL shrink and retired-test/tool migration remain pending. The 14 fresh-source samples are a meaningful confidence improvement, not a substitute for these gates.

## Physical UEDB4 retirement completed — 2026-10-12

At the user's direction, the 14-file V5 source sample was accepted and further per-file V5 verification was explicitly skipped for this deletion. On PRD, `catalog/bin/retire-uedb4-containers.php --apply` deleted **235,239** canonical `metadata/{game}/{shard}/*.uedb4` files, **29,885,781,801 bytes (27.833 GiB)**, with **zero unlink failures**. The command only selected numeric `.uedb4` file basenames under numeric game/shard directories; it did not touch `.uedb5` files, SQL tables or unrelated storage. These disk savings do **not** reduce the V5 candidate SQL tables.

The July 2026 backup evidence was not established as current; deletion was performed on explicit user instruction without awaiting an independent October recovery-copy gate. Remaining Epic parity, Zen/IoStore production ingestion, SQL footprint optimisation and obsolete-tool cleanup are separate open items. Earlier sections of this document describing physical V4 retirement as pending are historical checkpoints and are superseded by this section.
