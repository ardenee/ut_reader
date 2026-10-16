# Epic-source compliance audit — durable batches

This is a **coverage register, not a certification**. A passing validator checks registered obligations, not the unknown remainder of a source tree.

## Batch protocol
1. Pin authoritative source root, revision or snapshot hashes. Never reference mutable `latest`.
2. Inventory from two independent directions: entry-point/call graph and serialized structures/dispatch registrations. Persist each finding as a record; record explicit exclusions.
3. Reconcile both inventories and publish an unresolved-operations list. New batches only append/update records against their pinned sources.
4. Every obligation carries a stable ID, source anchor/hash, applicability, implementation anchor/hash, tests and disposition.
5. Verify evidence hashes and tests before changing disposition to `verified`. Any fingerprint mismatch makes an entry stale.
6. Commit the batch checkpoint and report the next batch ID. Do not perform a full package scan as part of this audit.

Use `php catalog/bin/verify-source-compliance.php --all --strict` locally. Strict returns nonzero for unresolved, unreviewed and stale entries.

## Status
- B001 UE5 script-object provenance: **partial / open** (synthetic test passes; full family integration not verified).
- B002 onward: not started.
- UE1–UE4: not certified or inventoried here.

Do not mark a profile compliant until independently reconciled inventory and all five gates have passed.
