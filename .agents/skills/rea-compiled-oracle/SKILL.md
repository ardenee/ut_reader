---
name: rea-compiled-oracle
description: Investigate compiled Core/Engine file serializers via installed REA plus Ghidra without mistaking pseudocode for original Epic source.
---

# REA compiled-source oracle for UnrealDB

Apply when pinned Epic method bodies are unavailable, when game-version headers and compiled code disagree, or when a precise on-disk FArchive call order must be proven.

DEV tools: REA 6.7.0 via npm global rea; Ghidra 12.1.4 at C:\Tools\ghidra_12.1.4_PUBLIC; Java 25. Use C:\Temp\UnrealDB\O06\tools\rea-ghidra.ps1 for correct environment.
Account-wide Codex REA MCP is registered at C:\Users\arden\.codex\config.toml. Account-wide skill C:\Users\arden\.agents\skills\reverse-engineer-anything. Restart Codex to refresh tools. In ChatGPT, invoke REA CLI via connected Desktop Commander; remote installation does not dynamically add ChatGPT MCP tools.
Analyzer targets: SHA-pinned C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll and Engine.dll. v432 Core.lib and Engine.lib are import-only, not function bodies. Do not modify Epic original binaries or source.

1. Read AGENTS.md, current O06 checkpoint and register; inspect git status. Choose one specific compiled discrepancy, not the whole corpus.
2. Pin binary and package SHA-256 and source game/version. Engine serializer is baseline; game adds source- or compiled-proven overlays. Package version cannot identify game.
3. Run rea doctor --provider ghidra --json and rea inspect with provider ghidra on a disposable local NTFS DLL. Use rea search/function/instructions/decompile/xrefs for exact function and resolve export jump thunks.
4. Confirm recovered field order and gate with source FArchive operators and compiled callee behavior. REA pseudocode is NOT exact original Epic C++. Never assume member declaration order is persisted archive order.
5. Implement bounded production PHP serializer; preserve unverified native tails; use focused positive/negative fixtures and independent genuine engine oracle where applicable. Open uncertainties remain PARTIAL.
6. Keep temporary output under C:\Temp\UnrealDB\O06, compact SHA/addresses/results in catalog/compliance. Update checkpoint. No G5 accepted or PRD release merely from static analysis.

Quick run:
  & 'C:\Temp\UnrealDB\O06\tools\rea-ghidra.ps1' inspect 'C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.dll' --provider ghidra --json

See catalog/compliance/TOOLS-REA-SETUP.md for provenance and setup.