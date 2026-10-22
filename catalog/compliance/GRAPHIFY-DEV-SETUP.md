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

## Verified pilot graphs (2026-10-22)

| Graph | Source path | Graph JSON | AST extraction | Result |
|---|---|---|---|---|
| `unrealdb` | `C:\Users\arden\source\repos\ut_reader` | `C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json` | 1,727 initial code files; incremental refresh after SQL dependency added | 10,792 nodes / 22,725 edges / 856 communities, 12,431,199 JSON bytes |
| `ue1-v0867` | `L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src` | `C:\Users\arden\Graphify\ue1-v0867\graphify-out\graph.json` | 237 code files | 9,659 nodes / 15,888 edges / 459 communities, 6,360,278 JSON bytes |

Both outputs also contain `graph.html` as an aggregated browser-viewable community graph, generated with `graphify export html --node-limit 2400`. The complete individual nodes remain available in `graph.json` and via CLI/MCP.

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
- A PHP file `catalog/bin/verify-uedb5-compact-v2-publisher.php` has a **genuine parse error at line 60** confirmed independently by `php -l` (exit 255). This unrelated issue was *observed*, not fixed as part of installing Graphify.
- The initial code-only UnrealDB scan skipped 7 SQL files without the optional grammar; `graphifyy[mcp,sql]` is now installed and the incremental refresh completed successfully.
- Graphify node/edge counts indicate searchable coverage, **not** UnrealDB serializer correctness. No O06 acceptance status was changed due to Graphify.
- Developer graph outputs may contain snippets or metadata from proprietary Epic source. Keep these files on DEV; do not push generated `graph.json` or `graph.html` to public GitHub or external Graphify Cloud.
