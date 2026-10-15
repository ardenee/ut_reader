# Documentation consistency audit — 15 October 2026

## Scope

Reviewed the repository's **118 existing Markdown documents** across the root, `catalog/`, `docs/` and `specs/` (excluding generated/dependency folders). Compared current operating guidance with the deployed V5 architecture, verified data/state, schema names, tested commands, and the retained source-conformance/migration records. This report adds a 119th Markdown document.

Historical source audits are **evidence snapshots**, not living installation instructions. Their original implementation and source citations were preserved rather than silently rewritten to erase migration history.

## Corrections made

1. Updated the root README and `catalog/README.md`: current production metadata is **UEDB5**, not `ue_file_metadata` / UEDB2 or UEDB4. Replaced pending-migration claims and linked authoritative current status.
2. Updated operational guides for performance/search, database migrations and the migration README. The active search/metadata model is the **compact V5 name dictionary + candidate tables + V5 dependency projections**, not retired term/export lookup tables.
3. Updated the specifications index and attached explicit **current-production / historical checkpoint** notices to dated V5 migration, staging, compact-candidate and UEDB4 documents. Preserved original Epic/source-backed behavior and audit findings.
4. Linked architecture, installation and production deployment entry points to [current project status](current-project-status.md).
5. Marked old CLI examples as **retired historical references**; removed unsupported current instructions for `compact-ue-term-ids.php` and obsolete controller-audit commands.
6. Added an authoritative [current project status](current-project-status.md) document that distinguishes 235,239 registered/validated V5 files, seven-game **bounded** lifecycle tests, production metadata-file inventory, and remaining UE5 Zen/IoStore acceptance limits.

## Verification

- The 118-document link scan reported **zero broken local Markdown links**.
- The stale-status scan reported **zero unqualified live-V4 production claims**.
- The documentation scan found **14 mentions of absent/retired CLI tools** in dated records. These are retained for historic provenance and explicitly marked **not current operator commands**, rather than falsifying old test evidence.
- `verify-uedb5-post-cutover-bounded.php` passed (exit 0), including **14/14** bounded container hashes and V5 registration/runtime gates.
- `verify-uedb5-candidate-cutover-readonly.php` passed (exit 0), including two required compact candidate tables and **zero** retired-table runtime references.
- Earlier production-independent lifecycle validation established successful isolated upload, reimport, backup restoration, and deletion/reconciliation for seven games, plus concurrent worker and failure/recovery examples. This documentation audit did **not** rerun destructive integration tests or a 235,239-file package scan.

## Known limitations and update policy

- Registration/validated status is not exhaustive Epic-source parity; do not remove source-specific unresolved branches or claim unsupported UE5 Zen/IoStore live ingestion.
- Do not delete either active V5 compact candidate table or misrepresent its database allocation as reclaimable V4 storage.
- Historical dated files may contain statements that were correct *at their checkpoint*, including old names and absent utility scripts. Follow the current status document for operations, and retain dated source notes for provenance.
- Future V5 runtime, SQL schema, or lifecycle changes should update **both** [current project status](current-project-status.md) and [integration evidence](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md), then review the relevant current guide and dated spec as appropriate.
