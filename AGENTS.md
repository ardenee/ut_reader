# UnrealDB development contract

The objective is **serialized Unreal file behavior identical to the selected Epic source**. Passing UnrealDB's own synthetic tests is not proof of parity.

Read [.agents/skills/unrealdb-epic-parity/SKILL.md](.agents/skills/unrealdb-epic-parity/SKILL.md) for any UnrealDB source, fixture, verification, or release work. Apply the local `ponytail` skill for reuse rather than introducing duplicate readers. Apply `investigate-first` on ambiguous failures and `verify-and-stop` for acceptance checks. Skills follow the [Agent Skills](https://agentskills.io/specification) open format.

- Work on DEV `game-amd` in `C:\Users\arden\source\repos\ut_reader`; canonical Epic source is under `L:\Source`. Never change pinned Epic source.
- Run PHP verification **only on DEV**. Deploy changed files to PRD and confirm source/destination SHA-256 byte identity. Never overwrite PRD `catalog/config.php` or deployment storage.
- Keep transient scripts and fixtures in `C:\Temp`. Do not put tests under `C:\Users\arden\Graphify`.
- For O06, update the checkpoint **before a task, at each test START/PASS/FAIL, and whenever the contract or next step changes**. The automatic dashboard is [catalog/compliance/UE1-O06-LIVE-CHECKPOINT.md](catalog/compliance/UE1-O06-LIVE-CHECKPOINT.md).
- Use `catalog/bin/run-ue1-o06-scoped.ps1` for contract-specific verification, not an arbitrary full-suite rerun. Direct runner accepts explicit tests and journals their real results.
- Verify actual native semantics where feasible. Track missing compiled or genuine-file oracles as OPEN; never label incomplete work ACCEPTED.
- Do not interrupt existing uncommitted work, delete test evidence, rewrite append-only journals, or erase failed runs. Check status/git diff before editing.
- Avoid cloud proxies, telemetry or external upload of Epic source without explicit approval. Graphify is optional **navigation**, not format evidence.
