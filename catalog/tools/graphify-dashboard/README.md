# Graphify / UnrealDB Development Verification

Installed on DEV game-amd only, at C:\Users\arden\Graphify.
Open http://127.0.0.1:8765/ in a browser **on DEV**.

This is a read-only view of UnrealDB verification evidence, not a test runner,
native Unreal oracle, or replacement for Epic source.

## What appears

- O06 operation status (NOT ACCEPTED until formal source-wide closure). G1 displays ACCEPTED, G2–G5 display IN PROGRESS, and G6 displays BLOCKED; this makes incremental gate work visible without falsely moving PARTIAL source sightings to MAPPED.
- Source-register totals, G1–G6 acceptance status and outstanding source contracts.
- Active source contract, last completed action, exact next recovery action.
- Current DEV test states, recent STARTED/PASS/FAIL event history.
- Git commits and tracked worktree changes, last *recorded* PRD deployment.
- Searchable class/source/reader/test inventory and remaining work.
- Source-contract focus/affected/gate test command **copy** controls only.
- Link to the existing separately indexed UnrealDB Graphify graph.

The dashboard polls /api/unrealdb every 20 seconds while visible.
Status polling does NOT regenerate the code graphs.

## Data provenance

Local-Dashboard-Server.py has a GET-only /api/unrealdb route using
UnrealDB-Status.py. Its fixed read-only inputs are:

- catalog/compliance/UE1-O06-ACTIVE.json
- catalog/compliance/UE1-O06-TEST-STATUS.json
- catalog/compliance/UE1-O06-TEST-EVENTS.jsonl
- catalog/compliance/UE1-O06-SERIALIZER-REGISTER.json
- catalog/compliance/UE1-O06-TEST-PLAN.json
- catalog/compliance/UE1-O06-WORK-EVENTS.jsonl
- G1–G6 acceptance section in catalog/compliance/PROJECT-STATUS.md
- Last three commits and tracked status from DEV Git

Journal records and source contracts remain in UnrealDB, not in Graphify.
The API accepts no user-selected filesystem paths or shell commands and
caches its immutable response for eight seconds.

"Last recorded release" comes from O06 work events, not a fresh PRD
filesystem/hash audit. PASS states also do not imply full Epic engine parity.

## Versioned files and reinstall

Six Python/HTML/CSS/JS files here are versioned snapshots of functional
files in C:\Users\arden\Graphify. Graph JSON, indexes and viewer settings
are not copied or deployed by this extension.

To reinstall on DEV in PowerShell, run:

    & 'C:\Users\arden\source\repos\ut_reader\catalog\tools\graphify-dashboard\Install-Graphify-UnrealDB.ps1'

The installer preserves changed existing Graphify files under
C:\Temp\Graphify-backups, verifies SHA-256, and rebuilds index.html.
It does NOT overwrite the Graphify host server or builder by default.
Only after inspecting local host modifications should the operator pass
-ReplaceHostScripts -RestartService.

The extension's CSS, UI and script are automatically embedded by
Build-Dashboard.py whenever Graphify regenerates index.html.

## Safety

- Graphify remains 127.0.0.1:8765 only, not exposed to the LAN.
- The new status API is GET-only; original Graphify Host and Origin checks remain.
- The UI uses textContent for repository/journal values, never injected HTML.
- The dashboard cannot run tests, deploy or change compliance state.
- Test scripts and temporary smoke files remain in C:\Temp, never Graphify.
- These DEV-only files are not copied to the production Apache webroot.

The DEV temporary-port smoke validation tests JSON status, source counts,
original graph routes, Host rejection, no POST command endpoint and rebuilt UI.
Use the project's O06 evidence, AGENTS.md and custom Epic-parity skill for
authoritative development/acceptance decisions.
