# Local, free Agent Skills

Upstream skill files are copied verbatim with their licence files. Original repositories were cloned to `D:\Temp\UnrealDB\third-party-skills` for inspection; no installation scripts or executables ran.

| Local skill | Upstream | Commit when reviewed | Licence |
|---|---|---|---|
| ponytail | https://github.com/DietrichGebert/ponytail | `96608e0` | MIT (local LICENSE) |
| investigate-first | https://github.com/JuliusBrussee/caveman | `2e068f7` | Apache-2.0 (local LICENSE) |
| verify-and-stop | https://github.com/JuliusBrussee/caveman | `2e068f7` | Apache-2.0 (local LICENSE) |

Upstream source clones were fast-forwarded on 2026-11-03. The three copied SKILL.md bodies remain unchanged upstream (Ponytail has only local line-ending differences). Caveman optional telemetry, CLI, MCP and proxy were not installed.

The [Agent Skills specification](https://github.com/agentskills/agentskills) is a folder convention, **not** a required runtime library or paid service. Reviewed upstream at `69ef37e`.

Caveman's optional telemetry, model router, CLI proxy, MCP wrappers and cloud features were **not installed or enabled**. No paid service is required. Graphify remains the existing local optional navigation service on DEV port 8765.
