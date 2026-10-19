# UE5.8.3 — Durable IoStore backup/restore job acceptance (19 October 2026)

Tested `catalog/bin/verify-iostore-backup-jobs-standalone.php` against guarded DEV isolated MySQL 127.0.0.1:3339.

**Initial run exit 2:** Test driver incorrectly selected container ID 2, an `indexed` only 27,240-member fixture. Production handler correctly refused with `Only ready IoStore containers can be backed up`. Restoring a nonexistent resulting manifest then failed. These failures were fixture selection, not a production parser defect.

**Corrected driver:** Select ready retained global TOC container ID 3, not indexed-only ID 2, and use the selected TOC checksum. Repeat execution **exit 0**, `ok=true`: backup durable job ID 24 completed, retained two source files and returned an exported backup; restore job ID 25 completed, restored separate disposable container ID 7 with `retention_status=ready`, and enqueued name-index job 26 and publication job 27. This global TOC has **zero package members**, as expected. It validates the real background job lifecycle and its downstream queue handoff, not full member publication.

Independent earlier real `FactoryGame-WindowsServer` container service backup/restore covered 27,240 members plus SHA256, while the corrupt-UCAS backup regression returned the correct refusal without inserting target records. Do not conflate the two coverage cases. No production Satisfactory import performed.

Search: scanning the **first 5,000** genuine Satisfactory Zen V5 snapshots through `Uedb5ClassicDependencyResolver::exportCoverageRows` found zero nested dotted `local_path` values. Thus this particular fixture cannot establish hierarchical descendant-path search semantics; it is not proof of a bug or full parity. Earlier exact export/name/negative real search test `verify-ue5-583-real-zen-compact-search.php` passes. Keep descendant-path verification open.

Remaining overarching UE5 workflow/source reachability and differential gaps remain open; do not set `RECOVERY.json.scope_complete=true`.
