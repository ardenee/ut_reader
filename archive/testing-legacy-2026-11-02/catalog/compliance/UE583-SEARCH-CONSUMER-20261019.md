# UE5.8.3 — Search consumer contract reconciliation, 19 October 2026

DEV/PRD baseline: `6b14ccea`. Production V5 search is `catalog/src/Infrastructure/Search/Uedb5CatalogMetadataSearch.php`, which uses compact name dictionary and candidate tables, reads persisted V5 metadata, and verifies returned matches against authoritative V5 snapshots.

Executable check: `catalog/bin/verify-uedb5-parity-targeted-search.php` exited 0, `ok=true`. `catalog/bin/verify-search-exception-autoload-contract.php` also exited 0.

**Unresolved legacy-contract mismatch:** `catalog/bin/verify-search-descendant-prefix-contract.php` exited 2. It statically searches `PdoCatalogSearchRepository.php` for legacy methods `collectExactMetadataMatches`, `collectDescendantMetadataMatches`, legacy `ue_terms.value_prefix LIKE` and `ue_export_lookup` SQL references. The active V5 path instead uses `Uedb5CatalogMetadataSearch` and its exact compact index followed by V5 hydration, with path-key descendant checks. Therefore a failed static string expectation cannot, on its own, prove that actual V5 descendant search is wrong, and neither test should be marked as passing until a runtime behavioral test with a real hydrated V5 snapshot validates exact-vs-descendant priority.

**Remaining acceptance:** Add an isolated real-Satisfactory Zen query/search fixture that executes the active public repository, proves exact-name and descendant-qualified-path results and zero false positives, including failure/availability semantics. Audit normal search/API dispatch callsites to this repository before closing `metadata_query_search` in `RECOVERY.json`.

No production code changed; `scope_complete=false` remains appropriate.

## Actual real-file V5 search acceptance (19 October 2026)

`catalog/bin/verify-ue5-583-real-zen-compact-search.php` executed read-only against guarded isolated 127.0.0.1:3339 and exited **0**, `ok=true`. Its inventory contains all 27,240 real Satisfactory members. Production `Uedb5CatalogMetadataSearch` returned the actual `Export object` from authoritative snapshots for `Desc_RampInverted_8x4_Corner_02_C` (file 3), `Default__Desc_RampInverted_8x4_Corner_02_C` (file 3) and `BP_ResourceDeposit611` (file 2). It returned the exact `None` name map row from file 1, and zero results for a nonexistent export string. Search rejects candidates absent from authoritative V5 data.

This closes **exact name/export-object search for the tested Zen fixtures**. Hierarchical qualified descendant paths still require a real nested-path fixture and integration test; do not mark that separate branch or the entire search workflow as source-complete. The legacy static descendant-prefix contract still fails as previously recorded and is not counted as passing.
