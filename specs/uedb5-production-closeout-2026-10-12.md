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
