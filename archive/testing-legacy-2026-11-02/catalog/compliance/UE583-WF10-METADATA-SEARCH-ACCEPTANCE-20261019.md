# UE5.8.3 WF10 — production V5 metadata search acceptance

**Accepted within the persisted UE5 V5 metadata search scope.** Production `PdoCatalogSearchRepository` routes V5 searches into `Uedb5CatalogMetadataSearch` and the compact name dictionary, name-candidate and object-candidate tables. The isolated genuine 27,240-member Satisfactory dataset is searched directly on DEV MySQL port 3339, guarded by server port and datadir identity; no PRD data is modified.

`catalog/bin/verify-ue5-583-real-zen-compact-search.php` passes positive exact export-object and name matches for real Zen members and no-match cases. `catalog/bin/verify-search-descendant-prefix-contract.php` passes the V5 compact-candidate route and nested object-name prefix/descendant behavior using isolated SQL projections. Both verified exit 0 on 2026-10-19. The search route consumes the V5 metadata fields already covered by the independent Epic-native Zen and classic extraction comparisons, without pretending Unreal Engine itself provides a catalog search interface.

This acceptance covers lookup semantics for the persisted UE5 format, not browser authorization, future engine-version layouts, or unrelated maintenance/repair behavior. Other production workflows remain separately gated.
