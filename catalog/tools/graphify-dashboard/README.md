# Graphify local source graph dashboard

The DEV dashboard at http://127.0.0.1:8765/ is now **Graphify-only**: master projects, indexed source graphs, node/edge/size statistics, graph search and sorting, source folder scanning, and HTML viewer node-limit controls.

The old UnrealDB/Epic compliance and acceptance panel is no longer injected into the landing page. The live UnrealDB test register and historic certification evidence remain in the repository under `tests/`; they do not drive Graphify's home page.

## Deployment

- Versioned builder: `Build-Dashboard.py`. It reads indexed projects under `C:\Users\arden\Graphify` and generates `index.html` without the old audit extension.
- Install on DEV with `Install-Graphify-UnrealDB.ps1` (historical installer filename retained for compatibility). It backs up and updates only the Graphify builder, regenerates the landing page, and verifies HTTP if the local dashboard service is available.
- Existing local Graphify server customizations are preserved. `-ReplaceHostScripts` is an explicit opt-in for replacing the local server with its versioned variant; `-RestartService` is optional, with active indexing jobs protected.
- The Graphify server binds only to `127.0.0.1:8765`. No source graphs or Epic code are uploaded to the Internet. The install does not modify PRD.
- Old `UnrealDB-Dashboard.*` and `UnrealDB-Status.py` sources are retained for historical compatibility with the local server's separate diagnostic endpoint, but are not included in the Graphify home page.

## Content

Graphify tracks source nodes, relationships, communities, indexed projects and graph JSON size. It does not certify binary package parsing or native engine behaviour. For engine parity acceptance status, use the separate repository test register rather than the Graphify dashboard.
