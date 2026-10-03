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

Requires / Required By compares the normalized resolved source-to-target graph. Package/alias fallback identity is audited separately so a UI fallback is not mistaken for an authoritative dependency resolution.

## Search parity

Step 9 tests end-user search behaviour using a deterministic bounded query corpus from live V4 names, imports, and exports. When Step 8 is skipped, Step 9 does not claim source-reparse validation; it compares the completed current V5 payload and its published V5 projections against live V4 behaviour.

Each search scope is tested independently so a generic FName hit cannot mask a broken import or export search path. The parity corpus respects production metadata-search semantics and samples only terms of at least three UTF-8 characters, matching PdoCatalogSearchRepository::MIN_BROAD_QUERY_LENGTH. V5 uses the appropriate narrow candidate index, then hydrates only the indexed candidate row positions from authoritative UEDB5 blocks before accepting the match; parity search must not materialize whole package snapshots.

If an authoritative UEDB5 name exists but `ue_uedb5_name_candidates` does not expose it, repair the disposable projection with `catalog/bin/sync-uedb5-name-candidates.php`. The synchronizer rebuilds expected rows through the same `Uedb5SqlProjectionBuilder` used by Pass 1, writes only `ue_uedb5_search_keys` and `ue_uedb5_name_candidates`, and immediately verifies each repaired file. It does not reparse source packages and does not modify provider, object, or dependency projections.

## Intentional source-correction differences

Parity does not mean preserving a known V4 mistake. Intentional differences must be explicitly allow-listed and must carry source evidence in V5.

The initial rule is `ut3_source_unresolved`:

```text
V4 missing -> V5 unresolved
```

This is accepted only for UT3 when the V5 dependency result contains UE3 source-policy/resolver evidence showing that runtime/cooked state prevents a proven missing decision. A bare outcome change without that evidence remains a regression.

The same allow-list is applied wherever that correction affects aggregate behaviour, including base-game missing totals and duplicate-provider cases. New source fixes must add a similarly narrow rule; there is no generic "V5 wins" exemption.

## Provider aliases

Classic V5 provider projection must include one primary provider key plus every `ue_file_package_aliases` identity for the staged physical file. Alias source IDs remain the catalogue alias IDs. Zen `FPackageId` providers do not invent package-name alias identity.

Files staged before this provider-key correction can be repaired without source reparse or dependency rebuild:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\sync-uedb5-provider-keys.php --game=ut99 --apply --continuous
```

This command writes only `ue_uedb5_provider_keys`.

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
