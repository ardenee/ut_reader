# UEDB5 candidate SQL footprint — measured redesign proposal (2026-10-12)

## Verified baseline

Production MySQL/InnoDB `ue_uedb5_name_candidates` has approximately 320,561,132 rows and occupies 47,627,370,496 bytes of data plus 42,059,186,176 bytes of indexes. `ue_uedb5_object_candidates` has approximately 355,295,715 rows and occupies 23,141,007,360 bytes of data plus 35,413,377,024 bytes of indexes. These are `information_schema` estimates. All three sampled lookup plans use existing composite indexes; there is no authorised safe drop in the current physical schema.

## Duplication and proposed direction

The name candidate row includes `file_id BIGINT`, `name_key_hash BINARY(16)`, `name_key_length INT`, `name_key_fingerprint BINARY(32)`, and `first_name_index INT`. The name candidate PK is `(file_id,name_key_fingerprint)`; the lookup index is `(name_key_hash,name_key_length,name_key_fingerprint,file_id)`. Both structures carry the full 32-byte fingerprint per high-cardinality name candidate. The separate global `ue_uedb5_search_keys` dictionary already carries `key_fingerprint`, `key_hash`, `key_length` and normalized text.

**Candidate design, not implemented:** give each globally distinct normalized name a stable compact surrogate key in the name dictionary (for example `BIGINT UNSIGNED`; measure `INT UNSIGNED` only if global cardinality and growth guarantees support it). Store `(file_id,name_id,first_name_index)` in a name candidate PK, and provide a reverse index `(name_id,file_id)`. Exact text input resolves via hash+length+SHA-256 and ultimately the dictionary's normalized bytes, yielding an unambiguous dictionary ID; results are then looked up by name ID. An MD5 collision or case-normalisation edge case must never merge two distinct identities. The dictionary lookup must continue to support prefix/partial search and exact candidate hydration.

Potential name-row **logical field width** drops from 64 bytes (8+16+4+32+4) to 20 bytes (8+8+4) with an 8-byte dictionary ID, **44 bytes/row**, i.e., ~14.1 billion bytes across 320.6m rows *before* adding secondary/primary B-tree overhead. This is an **illustrative raw-field-width scenario, not a disk saving prediction**: InnoDB record overhead, duplicated PK bytes in secondary indexes, dictionary storage, fill factor, migration headroom and real compression all matter. Exact savings must be measured with a representative shadow table, and a primary key that changes name order must preserve SQL/query semantics.

Object candidates already have a narrow `object_name_hash BINARY(16)` plus `object_name_length INT` and 8-byte optional public export hash. Their `object_name_hash,object_name_length,file_id,object_kind,object_index` lookup index and file-local public export hash index serve current queries. A shared compact name ID might shrink object lookup indexing further **only if** the object name equivalence semantics and dictionary key normalisation match exactly. VerifyExport/VerifyImport and Zen PublicExportHash source identity must remain source-backed in V5.

## Required acceptance before any live SQL changes

1. Build a separate disposable *small* shadow design from controlled V5 fixtures and representative file IDs, not a duplicate of the 676-million-row live corpus. Keep production table/index definitions intact.
2. Prove round-trip identity and collision handling: UTF-8/raw source names, case-equivalence, nontrivial FName numbers, empty/whitespace names, 64-bit Zen IDs and all expected lookup outcomes. Store no guessed source graph in SQL.
3. Replay exact-name, prefix, package-name, object-name, cross-file provider and dependency candidate queries against both designs; hydrate authoritative .uedb5 rows and compare matched IDs and indexes, including duplicate-provider ambiguity handling.
4. Benchmark EXPLAIN and latency for selective and high-frequency keys, largest games, and both warm/cold cache. Record row/table/index byte sizes from a real disposable table.
5. Design a safely resumable non-blocking migration and explicit rollback with space headroom, and only then decide whether to replace any current table in production.

**No SQL schema, index, or data change was made by this proposal.** This is a validated baseline plus an optimisation hypothesis, not an estimated guaranteed 40-GB reduction.
