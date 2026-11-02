# D031 — retained IoStore TOC identity on repeated indexing

**Existing original operation:** `UE5-583-FILE-STORE-001` / B006. This is a distinct *UnrealDB durable ingestion/retry integrity* defect in preserving the FPackageId/chunk identities used by the source-defined file package-store path; it is **not** a claim that Epic's live `FFilePackageStoreBackend` has a database retry implementation, nor new mounted-order/redirect rules.

## Historical discrepancy and correction

Prior to code commit `1bb60bd9`, `CatalogIoStoreContainerIndexer::index()` accepted an existing container identified by (game ID, retained TOC SHA-256) if its persisted `ue_iostore_packages` **COUNT(*)** matched the number of ExportBundleData entries from the retained `.utoc`. A package ID could be swapped, its TOC row position altered, a main chunk silently relabeled as an optional segment or its stored logical size changed **without affecting the count**. The old retry then returned `status=existing`, leaving a corrupt physical-provider/member inventory usable by downstream V5 readers, import/name-index and publish workflows.

The corrected retry inside the existing transaction:

- Locks and validates the existing container's `container_id`, `toc_version`, `container_flags` and `package_count` against the retained original TOC.
- Locks and reads **all** stored member records in `toc_index` order, comparing every TOC index, the exact binary eight-byte `FPackageId`, `chunk_index` (including optional segment 1) and `logical_size` with the export-bundle entries computed from the retained TOC.
- Rejects extra, missing, swapped or modified rows with an explicit *repair required* exception. On failure it rolls back and never overwrites existing rows, publishes a member or changes a stored package identity.
- Preserves the existing valid-idempotent retry response and the original full TOC inventory, including optional chunks (B012/D030 correction).

`SELECT ... FOR UPDATE` remains in MySQL, including the new ordered member scan, so a corrupt identity cannot be accepted between per-container header validation and member comparison under InnoDB row locking. This code does not introduce a new schema, migrate data, delete optional segments or alter the retained `.utoc`.

## Executable red-before/green-after regression

`catalog/bin/verify-uedb5-b006-container-retry-integrity.php` pins and inspects **the actual historical indexer code** at `a880dc20`, where the count-only branch is visible. It then runs the **unchanged real production indexer class** using a disposable `Pdo\Sqlite` instance with `information_schema` and `FOR UPDATE` syntax adapted *only* in its test database layer. It loads the retained, authentic Satisfactory Dedicated Server `FactoryGame-WindowsServer.utoc` read-only and indexes **27,240 real chunk identities**. It tests an original write to disposable memory, a valid repeat, nine corrupt states (swapped package ID, chunk index, size, TOC position, container version/flags/ID/count, extra row and missing row), transaction rollback on every rejection, then restores exact identities and confirms a successful retry.

All **21 independent checks** pass. The historical count-only guard would have incorrectly accepted a changed package ID with the same row count. No production MySQL or game file is modified. The test's simulated SQLite transaction/locking compatibility does **not** establish a production concurrent MySQL race differential; a true multi-connection MySQL lock-contention acceptance remains separate.

## Source-defined scope and remaining limits

The pinned Epic UE5.8.3 `FilePackageStore.cpp`/IoStore reader uses package IDs and chunk indices as physical identity, but it does not contain this PHP/MySQL persistence lifecycle. D031 is an **application consistency prerequisite to source-aligned package identity**, not a new Epic engine operation. B006's existing source-based mounted ordering and redirect tests remain unchanged.

The D031 driver is an **eighth independent B006 acceptance driver**. The original operation denominator stays 18. The new 21-check prerequisite supplements, rather than replaces, original B006 mounted-source evidence. Global UE1–UE5 compliance, missing live UE5 registration, and independently cooked optional segment parity remain unverified. PRD has no new schema or metadata backfill; existing corrupt index rows, if any, will require a separately authorized repair after being detected on a retry.
