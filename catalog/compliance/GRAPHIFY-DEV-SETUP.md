# Graphify on DEV — local code intelligence pilot

Updated: 2026-10-22. Owner machine: `game-amd` (`192.168.0.51`).
Status: **INSTALLED AND VERIFIED**. This is an OPTIONAL development/search tool. Epic source remains the sole Unreal format behavioral authority. Graphify may omit macro-expanded paths, conditional code, and custom archive semantics; never treat a graph link as proof of file compatibility.

## Local installation

- Free/local Graphify `graphifyy==0.9.93`, Apache-2.0. No Graphify Cloud or API keys configured.
- Windows `uv==0.13.5` via WinGet; Python 3.13 uv-managed user tool environment.
- Installed extras: `mcp`, `sql` (`tree_sitter_sql`).
- Executables: `C:\Users\arden\.local\bin\graphify.exe` and `graphify-mcp.exe`.
- User PATH includes `C:\Users\arden\.local\bin` (new terminal windows inherit it).
- No running service, open listening port, or Desktop Commander replacement. MCP tested on-demand using local **stdio**, not exposed to the network.
- Refresh helper: `C:\Users\arden\Graphify\Index-Code.ps1`.
- End-to-end MCP test: `C:\Users\arden\Graphify\Test-MCP.py`.
- All generated indexes, including metadata and rendered HTML, live **outside the Git checkout** under `C:\Users\arden\Graphify\`, not PRD.

## Verified local graphs and interactive dashboard (2026-10-22)

| Graph | Source path | Graph JSON | AST extraction | Result |
|---|---|---|---|---|
| `unrealdb` | `C:\Users\arden\source\repos\ut_reader` | `C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json` | 1,727 initial code files; incremental refresh after SQL dependency added | 10,809 nodes / 22,747 edges / 857 communities, 12,440,439 JSON bytes (refreshed after commit `a96e668a`) |
| `ue1-v0867` | `L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src` | `C:\Users\arden\Graphify\ue1-v0867\graphify-out\graph.json` | 237 code files | 9,659 nodes / 15,888 edges / 459 communities, 6,360,278 JSON bytes |

All outputs contain `graph.html` as an aggregated browser-viewable community graph (generated with `graphify export html --node-limit 2400`). The complete individual nodes remain available in `graph.json` and via CLI/MCP.

**Interactive dashboard with Add & scan:** open **http://127.0.0.1:8765/** on DEV (or the `Graphify Dashboard` desktop shortcut). Enter a DEV folder (for example `L:\Source\Engine\UE1`) and optionally a project name; click **Add & scan**. A local Python service runs Graphify's existing code-only index script (four workers), writes STARTED/PASS/FAIL to `C:\Users\arden\Graphify\Dashboard-Index-Journal.jsonl` and updates the dashboard automatically. Only one graph scans at a time; per-project output is saved as `scan.log`. The service listens only on 127.0.0.1:8765, checks local Origin and a per-process CSRF token, and does not accept raw commands. A Windows user Startup shortcut starts the service on login; run `C:\Users\arden\Graphify\Start-Dashboard.ps1` to restart it if necessary. The old static `C:\Users\arden\Graphify\index.html` still works for browsing, but only the local HTTP dashboard can launch scans. `Build-Dashboard.py` automatically scans saved `graph.json` files and `project.json`; `Index-Code.ps1` updates the HTML view and dashboard after successful extraction.

**13 currently indexed projects:** UnrealDB, UE1 v0.82, v0.83, v0.84a, v0.86x, v0.867, v1.200, v1.224 (INCOMPLETE), v1.227, UT v400, UT v432, the separate `L:\Source\Engine\UE1\Source` tree (version unknown), and **`ue1-full-root`** (`L:\Source\Engine\UE1`, created by a real dashboard Add & scan acceptance test). The full root overlaps the unversioned source tree. **13 graphs currently total 136,091 nodes and ~236 MiB** of local graph/cache/HTML storage. Counts include duplicate nodes in overlapping index roots and must never be interpreted as unique source symbols. The local dashboard is the authority for current index metrics. All nine additional Games version trees plus the unversioned Engine/Source tree completed code-only indexing and HTML export successfully. Some source files could not be fully parsed; see limitations.

**Reusable local scripts:** `C:\Users\arden\Graphify\Build-Dashboard.py`, `Local-Dashboard-Server.py`, `Start-Dashboard.ps1`, `Index-Code.ps1`, `Index-UE1-Sources.ps1`, and `Test-MCP.py`; versioned backups live under repository `tools/graphify/`. `Index-UE1-Sources.ps1` preserves an append-only `UE1-Index-Journal.jsonl` with STARTED and terminal PASS/FAIL for each graph, and skips graphs already saved. For any new project, use `Index-Code.ps1 -Name <new-id> -Source <directory> -Workers 4`; its dashboard entry is then added without further manual page editing. No Graphify Cloud account or *network-facing* HTTP service is involved; the optional interactive dashboard uses loopback HTTP on DEV only.

## Usage from any PowerShell terminal on DEV

```powershell
$g = "$env:USERPROFILE\.local\bin\graphify.exe"
& $g query 'CatalogUE1OriginalUnrealfileDatabaseDataReader' --graph 'C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json' --budget 1000
& $g query 'UClass SerializeHeader FProperty' --graph 'C:\Users\arden\Graphify\ue1-v0867\graphify-out\graph.json' --budget 1000
& $g god-nodes --graph 'C:\Users\arden\Graphify\ue1-v0867\graphify-out\graph.json' --top 10
Start-Process 'C:\Users\arden\Graphify\unrealdb\graphify-out\graph.html'
```

Refresh either graph (local code-only AST, no external model/API):

```powershell
& 'C:\Users\arden\Graphify\Index-Code.ps1' -Name unrealdb -Source 'C:\Users\arden\source\repos\ut_reader' -Workers 4
& 'C:\Users\arden\Graphify\Index-Code.ps1' -Name ue1-v0867 -Source 'L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src' -Workers 4
```

For another project, choose a new `-Name` and source directory. Indexes are manual/incremental, not continuously updated. Regenerate HTML after significant graph changes:

```powershell
& $g export html --graph 'C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json' --node-limit 2400
```

## MCP (separate from Desktop Commander)

The local executable `C:\Users\arden\.local\bin\graphify-mcp.exe` supports stdio. An MCP client can launch it with these arguments:

```text
--graph C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json
```

Verification was performed by `Test-MCP.py` using an MCP Python client via stdio: initialized `graphify 0.9.93`, discovered **10 tools** (`query_graph`, `get_node`, `get_neighbors`, `get_community`, `god_nodes`, `graph_stats`, `shortest_path`, `list_prs`, `get_pr_impact`, `triage_prs`), and successfully invoked `graph_stats`. Run again:

```powershell
& "$env:APPDATA\uv\tools\graphifyy\Scripts\python.exe" 'C:\Users\arden\Graphify\Test-MCP.py'
```

**This does not automatically connect Graphify as a first-class ChatGPT plugin.** Existing ChatGPT sessions can run `graphify` on DEV through Desktop Commander. The direct local MCP endpoint can be configured separately in an MCP-capable client. Do not expose HTTP MCP on the LAN or Internet without explicit authentication and TLS.

## Limitations and distinct source findings

- Original Unreal v0.867 uses non-UTF8 code and extensive C++ macros; Graphify reported **41 files with syntax/extraction issues**, and some may be only partially parsed. Use `rg`, compiler probes and actual Epic source for verification.
- Graphify located a stray Desktop Commander output line in historical `verify-uedb5-compact-v2-publisher.php`. Syntax was corrected and both DEV/PRD lint passed; the seven-game test was then **retired on 2026-10-22** after confirming no automated callers and that its pinned live fixtures are unavailable in the configured test database. Replacement isolated V5 checks passed (see `specs/uedb5-migration-validation.md`). **The `ue_uedb5_files` table and production compact publisher remain active, not obsolete.** Git history retains the removed script.
- The initial code-only UnrealDB scan skipped 7 SQL files without the optional grammar; `graphifyy[mcp,sql]` is now installed and the incremental refresh completed successfully.
- Graphify node/edge counts indicate searchable coverage, **not** UnrealDB serializer correctness. No O06 acceptance status was changed due to Graphify.
- Developer graph outputs may contain snippets or metadata from proprietary Epic source. Keep these files on DEV; do not push generated `graph.json` or `graph.html` to public GitHub or external Graphify Cloud.
