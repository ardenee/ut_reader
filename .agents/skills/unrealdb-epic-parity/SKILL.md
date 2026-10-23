---
name: unrealdb-epic-parity
description: Source-first UnrealDB parser development, UE1 O06 audit, compact checkpointed verification, resumable evidence and DEV-to-PRD source-parity deployment.
license: MIT
compatibility: Windows PowerShell 5.1+, PHP 8.5, Python 3, git and Desktop Commander on game-amd DEV.
---

# UnrealDB Epic source parity

1. **Resume, don't replay.** Read the live checkpoint, last O06 event records and `git status` before choosing work. Do not repeat a known completed test unless its transitive inputs changed or it is an explicit milestone gate.
2. **Specify the contract.** Identify one Epic engine/version/function, exact source path, structural read/write effects, and gaps against the actual production reader. Record contract and next step **before modifying code** via `python catalog/bin/update-ue1-o06-checkpoint.py --activate ...`.
3. **Source authority.** Use build-selected Epic C++ including version boundaries, inherited serializers, `FArchive` operations and linker maps. Graphify and ripgrep locate code; only Epic source and authentic engine/oracle evidence establish behavior. Keep source SHA and specimen provenance.
4. **Reuse first.** Prefer an existing shared reader/helper. Preserve malformed-input validation. A new serializer requires source assertions, positive and negative bytes and a genuine/compiled comparison where possible; unverified source family or memory effects stay explicitly unverified.
5. **Tiered tests.** Run `catalog/bin/run-ue1-o06-scoped.ps1 -Contract <name> -Tier focus` while editing; `-Tier affected` once stable; `-Tier gate` only at formal milestone acceptance. Add or update an explicit entry in `catalog/compliance/UE1-O06-TEST-PLAN.json` when introducing a contract.
6. **Immediately durable evidence.** Existing `run-ue1-o06-tests.ps1` writes append-only STARTED/PASS/FAIL events and per-test hashes; the live checkpoint updates automatically on each state transition. Update stage/next-step after the actual implementation and verification step, rather than waiting for chat end. On timeout the next run picks up at the first incomplete event.
7. **Close or keep OPEN.** Verify code passes its focus and affected tests, independently inspect source effects, and mark unresolved genuinely unknown behavior PARTIAL. Update `UE1-O06-SERIALIZATION.md`, `UE1-O06-OPEN-CONTRACTS.md` or their contract entry when **meaningful semantics** change, not to duplicate each identical PASS event.
8. **Release.** Commit/push only verified tracked changes; transfer only permitted changed files and compare SHA-256 on PRD. No PRD PHP tests. Leave `H:\UE5-583-runtime-probe` and `F:\UE5-583-versevm-probe` untouched.
9. **Stop verified work.** If the source contract, regression evidence and deployment pass, record the checkpoint and move to the next OPEN contract. Don't rerun expensive same-hash corpus tests simply to fill a chat response.

This skill is a workflow, not an oracle. Only full Epic-compliant observed file behavior permits acceptance.
