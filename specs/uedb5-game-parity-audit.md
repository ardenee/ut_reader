# UEDB5 game-level behavioural parity audit

Step 9 validates behaviour, not byte equality. UEDB4 and UEDB5 are different physical formats; the audit compares the catalogue/runtime decisions produced from them for the same fully migrated game.

The audit is read-only. It does not publish V5, mutate V4, cut over runtime registration, repair rows, or rewrite either metadata container.

## Readiness gate

A game is eligible for the parity audit only when all of these are true:

- every verified game file still has live format-4 production registration;
- every verified game file has a staged `ue_uedb5_files` registration;
- every verified game file has a current completed Pass-2 dependency checkpoint whose `dependency_payload_sha256` matches the staged V5 payload under `uedb5-dependency-pass-v1`;
- every verified game file has its primary V5 provider key;
- there are zero staged identities matching `ue_invalid_file_identities`.

Step-8 validation status is reported for visibility but is not a Step-9 admission requirement. Skipping Step 8 does not mark files validated and does not weaken the current-payload Pass-2 checkpoint.

`audit-uedb5-game-parity.php` refuses the actual audit until that invariant is true. `--preflight` is safe at any migration percentage and reports the current counts.

## Behavioural surfaces

The audit compares normalized behaviour across:

- dependency outcome counts and per-import decisions;
- selected physical provider file;
- selected provider export/object index;
- missing versus package-only versus common versus unresolved;
- base-game missing dependencies;
- resolved Requires / Required By relationship graph;
- package aliases and primary provider keys;
- duplicate-provider selection without provider merging;
- public/private VerifyImport decisions;
- invalid-file identity exclusions;
- exact metadata search results for names, imports, and exports.

## Authority and normalization

V4 behaviour is read through the existing production SQL/search surfaces. V5 behaviour is read only from `ue_uedb5_*` accelerators plus authoritative `.uedb5` hydration.

The V5 side follows the Step-4 rule:

```text
SQL candidate lookup -> UEDB5 authoritative row -> behavioural decision
```

The parity harness must not make UEDB4 bytes authoritative for V5. `Uedb5ParityV5ReadService` does not use `.uedb4` or `BlockedCompressedMetadataReader`.

Dependency comparisons use canonical outcomes:

```text
missing
resolved
package_only
common
unresolved
```

Provider parity is physical-file parity. Multiple same-name providers are never merged to manufacture coverage.

Requires / Required By compares the normalized resolved source-to-target graph. Package/alias fallback identity is audited separately so a UI fallback is not mistaken for an authoritative dependency resolution. When V4 has a required-package term, a missing/null V5 classic package key is itself a parity mismatch; the audit does not silently skip absent V5 identity.

## Search parity

Step 9 tests end-user search behaviour using a deterministic bounded query corpus from live V4 names, imports, and exports. When Step 8 is skipped, Step 9 does not claim source-reparse validation; it compares the completed current V5 payload and its published V5 projections against live V4 behaviour.

Each search scope is tested independently so a generic FName hit cannot mask a broken import or export search path. The parity corpus respects production metadata-search semantics and samples only terms of at least three UTF-8 characters, matching PdoCatalogSearchRepository::MIN_BROAD_QUERY_LENGTH. V5 uses the appropriate narrow candidate index, then hydrates only the indexed candidate row positions from authoritative UEDB5 blocks before accepting the match; parity search must not materialize whole package snapshots.

If an authoritative UEDB5 name exists but `ue_uedb5_name_candidates` does not expose it, repair the disposable projection with `catalog/bin/sync-uedb5-name-candidates.php`. The synchronizer rebuilds expected rows through the same `Uedb5SqlProjectionBuilder` used by Pass 1, writes only `ue_uedb5_search_keys` and `ue_uedb5_name_candidates`, and immediately verifies each repaired file. It does not reparse source packages and does not modify provider, object, or dependency projections.

If an authoritative UEDB5 export/cell export exists but `ue_uedb5_object_candidates` does not expose it, repair that disposable projection with `catalog/bin/sync-uedb5-object-candidates.php`. It derives candidates from authoritative UEDB5 export sections through the same projection builder, writes only `ue_uedb5_search_keys` and `ue_uedb5_object_candidates`, verifies the repaired rows immediately, and does not modify dependency projections.

V5 FName, import-object, and export-object discovery is intentionally normalized/case-insensitive because their candidate keys use normalized Unreal names. A V5-only Names, Imports, or Exports hit caused solely by source-case spelling (for example V4 `skin` queried as `Skin`, V4 import `GBLOOD1` queried as `Gblood1`, or V4 export `cube1` queried as `Cube1`) is an expected parity difference only when Step 9 re-reads the corresponding authoritative UEDB4 section for that file, finds the same normalized name with different casing, and finds no exact-case V4 source entry. V5 still hydrates the authoritative UEDB5 candidate/dependency row before the hit is accepted. V4-only hits, non-case differences, or files with an exact-case V4 source name remain failures.

## Intentional source-correction differences

Parity does not mean preserving a known V4 mistake. Intentional differences must be explicitly allow-listed and must carry source evidence in V5.

The initial rule is `ut3_source_unresolved`:

```text
V4 missing -> V5 unresolved
```

This is accepted only for UT3 when the V5 dependency result contains UE3 source-policy/resolver evidence showing that runtime/cooked state prevents a proven missing decision. A bare outcome change without that evidence remains a regression.

`classic_none_import_source_irrelevant` covers a separate UE1/UE2 source rule. Both UT99 `ULinkerLoad::VerifyImport()` and UE2/2.5 `ULinkerLoad::VerifyImport()` return immediately when `ClassPackage`, `ClassName`, or `ObjectName` is `NAME_None`, describing that import as not relevant in the current context. UEDB5 therefore records such an import directly as non-hard `runtime_derived` + `unresolved` with reason `source_irrelevant_name_none`; it does not run provider selection or VerifyImport matching for the row. A V4 `missing` result is an expected parity difference only when the authoritative V5 row preserves an actual `None` class-package, class-name, or object-name identity and carries that exact source-backed reason. Older staged rows that merely fell through to `package_root_unavailable` are stale and must be rebuilt rather than allow-listed.

`classic_none_import_ancestor_source_irrelevant` is the corresponding nested-import rule. Epic verifies the parent first and copies the parent's `SourceLinker`; when the parent returns early for `NAME_None`, no provider linker is established for its descendant. V5 therefore leaves such a descendant non-hard `unresolved` with reason `source_irrelevant_name_none_ancestor` and records the exact ancestor import index as resolver evidence. The parity allow-list requires that provenance; a generic unresolved child is not accepted.

The source also shows that UE1/UE2 NameMap loading can map a serialized non-`None` name to runtime `NAME_None` when its name-entry flags do not intersect the active edit/client/server context flags. UEDB5 deliberately preserves the raw serialized name and flags. That context-dependent mapping is a separate runtime-policy question for the first-principles source audit; the migration correction here only claims the deterministic `NAME_None` identities represented by the staged import plus the proven ancestor consequence, and does not invent a single runtime context.

UE1/UE2 `FName` identity is also exact with respect to whitespace: Epic compares `FName` values and does not trim their serialized text. A non-empty whitespace-only `FName` is distinct from `NAME_None`. V4 compact metadata preserves the raw ObjectName and serialized outer index even when a derived display path collapses; dependency rebuilding must therefore classify UE1/UE2 object imports from the serialized outer graph, not from a trimmed derived path. There is no parity exemption for this case: stale V4 `package_only` rows must be rebuilt so V4 and V5 both reach the same source-backed VerifyImport result.

The same allow-list is applied wherever a source correction affects aggregate behaviour, including base-game missing totals, required-package identity checks, and duplicate-provider cases. New source fixes must add a similarly narrow rule; there is no generic "V5 wins" exemption.

## Provider aliases

Classic V5 provider projection must include one primary provider key plus every `ue_file_package_aliases` identity for the staged physical file. Alias source IDs remain the catalogue alias IDs. Zen `FPackageId` providers do not invent package-name alias identity.

Files staged before this provider-key correction can be repaired without source reparse or dependency rebuild:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\sync-uedb5-provider-keys.php --game=ut99 --apply --continuous
```

This command writes only `ue_uedb5_provider_keys`.

When a resolver/source-identity correction affects known individual consumers, repair those exact files rather than replaying the game. V4 and V5 have separate exact-file paths:

```powershell
# Current production V4 dependency row + per-file summary + cached game counters.
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\rebuild-legacy-dependencies.php --file-id=143868 --game-id=3 --apply

# Staged V5 Pass-2 dependency payload/projection only.
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\migrate-uedb5-dependencies.php --game=ut99 --file-id=143868 --apply --force
```

The canonical V4 `--file-id` mode accepts only one verified current-format file, runs the normal production dependency rebuilder, refreshes that file's dependency-package summary, and rebuilds the small cached game-counter projection. It does not walk or rebuild dependency metadata for other files. V5 targeted `--file-id` mode runs the normal game preflight, verifies that the requested file is a verified staged V5 file in that game, and rebuilds/publishes only that file's Pass-2 dependency payload. A targeted V5 write requires both `--apply` and `--force`; it cannot be combined with `--continuous`, worker-pool options, or `--preflight`.

For the literal-whitespace FName correction, affected V4 consumer rows can be discovered without a package/UEDB scan. The diagnostic first performs an exact indexed `ue_terms(value_hash,value_length)` lookup and then follows the indexed `import_object_term_id` rows:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\diagnose-uedb5-whitespace-fname-dependencies.php --game=ut99 --value-hex=20
```

Only when a resolver/source-identity correction genuinely invalidates a broad set of files should Pass 2 be forced for the whole affected game:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\migrate-uedb5-dependencies.php --game=ut3 --apply --force --continuous --workers=4 --limit=1000 --progress-every=100
```

Game-wide `--force` still runs the normal preflight, remains V5-only, and bypasses only the already-current resume filter. It is a maintenance fallback, not the default response to a localized parity defect.

## Operator commands

Readiness check:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\audit-uedb5-game-parity.php --game=ut99 --preflight
```
Actual behavioural audit after readiness is true:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\audit-uedb5-game-parity.php --game=ut99 --max-details=100 --search-samples=150 --relation-samples=500
```

A nonzero mismatch exit means cutover for that game is blocked until every unexpected difference is explained and either fixed or represented by a source-backed expected-difference rule.

## Current implementation boundary

Step 9 infrastructure is implemented before any game is fully V5-ready. No game-level audit result is claimed yet.

The executable pieces are:

- `Uedb5GameParityAuditService`;
- `Uedb5ParityV5ReadService`;
- `Uedb5GameParityExpectedDifferences`;
- `audit-uedb5-game-parity.php`;
- `verify-uedb5-game-parity-audit-contract.php`.

The harness remains pre-cutover and read-only. Production runtime remains UEDB4 until later atomic cutover work.
