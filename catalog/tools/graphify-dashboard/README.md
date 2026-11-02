# Graphify / UnrealDB cross-engine file-parity dashboard

This read-only DEV Graphify integration at http://127.0.0.1:8765 now displays the ONE live [tests/contracts.json](../../../tests/contracts.json) UE1–UE5 engine/behaviour register and the [code-path index](../../../tests/code-paths.json). It no longer reads UE1-O06 legacy test events, serializer status journals or old project status tables, and it no longer creates test-execution commands.

**Historical G3 and G5 results remain accepted for their exact old scope** and are preserved under [archive/testing-legacy-2026-11-02](../../../archive/testing-legacy-2026-11-02/README.md); they are not automatically promoted to end-to-end UE1–UE5 contracts.

Current contract states are NOT_REVIEWED until individually mapped to existing native/Epic proof, or necessary new original parity tests. The Graphify graph is a code navigation aide, not an original-engine oracle.

## Install and use
DEV: C:\Users\arden\Graphify, accessible only on loopback. Versioned read-only source files are in this folder. Installer: Install-Graphify-UnrealDB.ps1. It backs up changes and may require a locally running Graphify service to restart; never changes production website or Git originals.

No commands run from the dashboard and no Epic source is uploaded anywhere. Dynamic project graph data is distinct from live test status; rebuild Graphify graph only when needed to reflect the reduced active source scope, excluding archived tests.
