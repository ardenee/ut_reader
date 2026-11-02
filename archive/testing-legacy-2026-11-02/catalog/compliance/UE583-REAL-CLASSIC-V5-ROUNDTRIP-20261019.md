# UE5.8.3 authentic classic package V5 staging acceptance

Verified 19 October 2026 on DEV pinned compiled UE5.8.3 engine content. Repeatable test: `catalog/bin/verify-ue5-583-real-classic-v5-roundtrip.php`, exit **0**, `ok=true`.

The test reads genuine `.uasset` files from `H:/UE5-583-runtime-probe/Engine/Content` with production `UnrealPackageReader5`, builds source-shaped summary/name/import/export/soft reference/dependency V5 sections with `Uedb5Ue5ClassicSnapshotBuilder`, writes a disposable `.uedb5` with `Uedb5MetadataContainer::buildToFile`, and verifies resulting manifest family and `Uedb5MetadataStagingReader::count()` section totals before deleting disposable output. Real engine files are never modified.

Result: **119 genuine UE5 classic asset V5 roundtrips** without error; UE5 versions 1004 (93), 1006 (1), 1007 (1), 1009 (1) and 1018 (23). **21 additional older-format assets** predate the UE5 summary-version field and are separately excluded from this UE5-specific builder. The first 140 eligible files are considered in native filesystem enumeration order; files over 40 MiB are skipped.

The first implementation of this verifier erroneously read `$manifest['sections'][$section]['row_count']`, but per actual V5 format section blocks are arrays and totals live in `$manifest['counts'][$section]`. That error caused false test failures for all files. It was corrected to use the public `Uedb5MetadataStagingReader::count()` accessor; **no production code change was necessary**.

Acceptance boundary: genuine classic UE5 reader → classic snapshot builder → V5 container persistence → V5 staging reader totals. Not independently native Epic field-by-field comparisons, not full SQL publication, not all classic variant branches, and not HTTP/app route acceptance. Overall UE5 certification status remains open. No production Satisfactory import or PRD database changes.
