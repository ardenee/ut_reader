# D030 — optional IoStore member publication and read boundary

**Original Epic operation:** `UE5-583-OPTIONAL-EXPORT-SEGMENT-001` in existing B012; this is a discovered production **workflow consumer discrepancy** in the handling of optional export-bundle chunks. It creates **no new original operation**.

## Epic source contract

The exact pinned Epic UE5.8.3 `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp` (SHA-256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`) uses `CreateIoChunkId(Desc.PackageIdToLoad.Value(), 1, EIoChunkType::ExportBundleData)` for the **optional segment** and a separate main `ExportBundleData` chunk with index **0**. `WITH_EDITOR` controls whether/when optional data is requested. B012 already verifies source version/editor branches and `Uedb5Ue5ZenIoStoreSnapshotBuilder::build` reads main index 0 and optional index 1, persisting optional imports and exports *under the owning ordinary package*, not as a second standalone package identity.

## Found UnrealDB difference

`CatalogIoStoreContainerIndexer::index()` properly preserved **both** chunk indices in the raw `ue_iostore_packages` TOC inventory, including `chunk_index=1`. But the downstream `CatalogIoStorePackageNameIndexer` selected all rows without checking `chunk_index`, and passed every selected package ID through a snapshot builder that **always reads the main chunk0**. The durable name-index and publish workers counted both chunks as separate packages. `CatalogIoStoreMemberCatalogPublisher` could extract the **optional chunk1 bytes** into an independently addressed `.zenpkg` source, then call `CatalogZenIoStorePackagePublisher` which staged metadata from the **main chunk0** for the same package ID; repeated file IDs and hashes could consequently be wrong or collide. `CatalogIoStoreIndexedPackageReader::inspect/extract` returned main metadata but optional logical size or payload when asked for a chunk1 TOC row. These behaviors mismatch Epic's ownership semantics, even without a live editor.

## Correction

Code revision **`c555f1c4`** implements:

- **Preserve** all raw TOC rows, including optional chunk1 entries, in `CatalogIoStoreContainerIndexer`; do not delete or rewrite previously stored optional rows and do not introduce a production migration. This raw `container.package_count` remains the number of indexed **ExportBundleData chunks**, not the count of distinct logical package IDs.
- Name-index jobs select and count **only main `chunk_index=0` rows**, checking the TOC chunk index again before metadata decoding. Main name-index failures alone govern the job's failure totals. Old optional rows with `metadata_status=indexed/failed/ready` do not cause endless name-index retries.
- Publish job queue, published/remaining/awaiting/failure counters select and count only main rows. An optional row already present in the database remains indexed as a raw chunk but is **never independently published**.
- Direct catalog publishing checks `m.chunk_index=0` **before writing an extracted member**, then checks the physical TOC chunk index0 again. A selected optional chunk produces a precise error and cannot masquerade as a valid independently published Zen file, even if its metadata status was previously marked `ready`.
- Direct indexed inspect/extract also rejects optional chunk rows rather than returning mismatched main metadata and optional-size payloads. Optional information still appears within the main package's V5 `optional_segment` and provenance. These are deliberate fail-closed boundaries, not silent data loss.

No changes were made to the Epic source, `Uedb5Ue5ZenIoStoreSnapshotBuilder`, the dependency resolver, V5 serialization or SQL schema.

## Before/after evidence and source execution

`catalog/bin/verify-uedb5-b012-optional-member-publication.php` checks pinned Epic main/optional chunk behavior, **four historical source files** at Git checkpoint `6cf82b0d` showing the missing predicates/guards, and current class implementations. A disposable **in-memory SQLite** fixture with two ordinary rows and two optional rows executes the actual name-index SQL extracted from the production implementation, checks job-selection/count boundaries, confirms historical name indexing **selected** optional row 21 whereas the corrected query returns only rows 20 and 22, and uses actual `CatalogIoStoreIndexedPackageReader::inspect` and `CatalogIoStoreMemberCatalogPublisher::publish` against disposable SQL to demonstrate that optional rows are rejected **before any retained file IO or persistent change**. All **27/27 assertions** pass.

The test reads the authentic **Satisfactory Dedicated Server** `FactoryGame-WindowsServer.utoc` on DEV (read-only) and found **27,240 main chunk0 entries, zero optional chunk1 entries**. It therefore does **not** claim a real cooked optional-segment differential. The separate B012 synthetic IoStore test contains real compressed main+optional export-bundle chunk bytes and verifies that optional data remains attached to main in the V5 snapshot. Combined original B012 acceptance now requires **five independent drivers and 16 assertions**, including the new D030 member-boundary driver.

**Remaining limits:** No live `WITH_EDITOR` UE5 execution, no independently cooked optional chunk1 container, no projection/backfill or existing optional-row cleanup. Optional TOC rows are retained as observed raw facts; any future optional-row explorer must expose the segment as such rather than a standalone package. Existing overall UnrealDB UE1–UE5 compliance remains unverified.
