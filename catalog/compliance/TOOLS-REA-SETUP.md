# REA + Ghidra installed on DEV (2026-10-23)

The goal is to recover source-evidence for the exact compiled UT99 v432 Core/Engine methods unavailable in the public C++ distribution. REA recovers evidence, not verbatim original Epic source. Use the pinned engine baseline first and then source-proven game overrides.

## Installed tooling and locations

| Tool | Installation | Verified details |
|---|---|---|
| REA CLI | npm global rea-agents@6.7.0 | rea --version=6.7.0 |
| Ghidra | C:\Tools\ghidra_12.1.4_PUBLIC | Official zip SHA-256 ddac49f903da9d5bac833e5cc79395098b9c33cfd3279be5f31bd00387d2d4db |
| Java | C:\Program Files\Zulu\zulu-25 | REA provider passes 64-bit JDK check |
| REA Codex MCP | C:\Users\arden\.codex\config.toml | Registered via rea setup --client codex --yes; original config saved to config.toml.rea.backup |
| REA account skill | C:\Users\arden\.agents\skills\reverse-engineer-anything | REA setup reports matching skill and 207 available contract definitions |
| REA UnrealDB skill | .agents/skills/rea-compiled-oracle/SKILL.md | Project-specific archive/native parity rules |
| REA PowerShell wrapper | C:\Temp\UnrealDB\O06\tools\rea-ghidra.ps1 | Sets GHIDRA_INSTALL_DIR, JAVA_HOME and provider env for Remote Desktop Commander |
| Disposable input | C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll and Engine.dll | SHA-pin each binary before analysis |

REA is installed from the documented official npm package, not by executing the linked ardenee/rea fork checkout. Official Ghidra 12.1.4 is installed from NationalSecurityAgency/ghidra and its ZIP digest matched.

## Usage

PowerShell:
  $rea = 'C:\Temp\UnrealDB\O06\tools\rea-ghidra.ps1'
  & $rea doctor --provider ghidra --json
  & $rea inspect 'C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.dll' --provider ghidra --json
  & $rea decompile 'C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.dll' --procedure '0x103ecec0' --provider ghidra --json

Run REA via the installed Codex MCP in new/restarted Codex sessions, or by invoking the wrapper via ChatGPT's Remote Desktop Commander. Installing local MCP does NOT automatically add a direct REA ChatGPT connector to an ongoing web session.

REA output evidence and exploratory scripts go under C:\Temp\UnrealDB\O06; compact function address/method field order and checksum evidence may be checked into catalog/compliance. Do not upload source or run untrusted binary code. Do not modify native-installed binaries, claim native loaded-value parity without runtime evidence, or deploy to PRD on tool readiness alone.

## Verified first use

REA 6.7.0 Ghidra 12.1.4 provider doctor completed with healthy=true and all 10 scoped checks passed. The CLI successfully inspected the authentic x86 UT99 v432 Engine.dll from the disposable Native-UCC folder (exit code 0), captured provider=ghidra and SHA-256 f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac. Raw REA evidence remains at C:\Temp\UnrealDB\O06\rea-v432-engine-inspect.json rather than being committed. This proves tooling readiness for native binaries, not correctness of any entire serializer or native runtime acceptance.
