# Epic-source-only Unreal file-format reference library

## Authority

The **pinned original Epic C++ source** is the only authority for file format,
version conditions and engine load behavior. The old
`UT_Package_File_Format.pdf` is an example of document organisation only,
**not evidence** for any field, type, byte count or condition.

A reference set is identified by **exact Epic engine release, source tree,
revision, platform/target compile conditions and relevant game fork**.
Never merge divergent engine forks into a generic UE1/UE2/UE3/UE4/UE5 specification.

## Required reference set for each release / fork

1. Source manifest: original source revision, source paths, hashes, target
   configuration and the actual compiler-defined feature flags.
2. Package identification and loader selection, including unsupported formats.
3. Package summary, version gates, custom versions, flags and exact byte layout.
4. Names and serialized FName/FString variants.
5. Import and export records, signed reference conventions and identity rules.
6. Dependency maps and preload dependencies, with precise conditions.
7. Object/property payload serialization and per-class differences.
8. Bulk data, compression, split sidecars and external/optional payloads.
9. Package-store/IoStore/Zen specifics **where actually present in this branch**.
10. Runtime lookup, import verification, redirects and relevant error handling.
11. Writer/reader agreement, including all compile-time and version branches.
12. Entry-to-result call paths for each actual loader and serializer.
13. Complete ordered decision tables: condition **copied exactly from source**,
    true/false next step, action and original source location.
14. Editable, layered flowcharts showing the entry path and separate subpaths
    instead of one unreadable all-in-one diagram.

## Mandatory step-by-step process

1. Identify the exact Epic source branch and build configuration.
2. Trace a real entry function to its return, following **every** called
   function that changes file interpretation or resolution.
3. Capture each serialized field/branch with the **exact source location**,
   source text and applicable version/compile conditions; no inferred constants.
4. Represent the branches as stable numbered steps, with edges to next steps.
5. Generate the reference document and editable flowchart **from the same
   source step manifest**, so diagrams and prose cannot silently diverge.
6. Reject generation if the pinned source hash, individual line anchors,
   an edge target or diagram coverage differs.
7. Keep the reference **source-only**. It must contain zero interpretations
   based on UnrealDB code, test expectations or previous unofficial documents.
8. **Separately**, map each Epic step to the real UnrealDB production code and
   all active callers. Mark missing/incorrect behavior as **not implemented**,
   correct the code, then check results against Epic. There is no acceptance
   merely because tests are green.
9. Only accept the whole entry-to-result path when every applicable step and
   called subpath has been reviewed, implemented and verified. Partial traces
   are explicitly labelled incomplete.
10. Keep diagrams and source documents together in Git. Rebuild them whenever
    the pinned source changes; regenerate public PDF handbooks after the
    underlying Epic-only sections are complete.

## File layout and deliverables

- `ue4-4.27.2/verifyimport/source-path.json`: pinned Epic step manifest;
  excludes any UnrealDB implementation statements.
- `ue4-4.27.2/verifyimport/source-path.md`: source-only, numbered trace.
- `ue4-4.27.2/verifyimport/source-path-*.mmd`: readable Mermaid diagrams.
- `ue4-4.27.2/verifyimport/source-path.drawio`: **editable** multi-page
  diagrams.net/draw.io document (can be exported to Visio from diagrams.net).
- `catalog/compliance/UE427-P07-SOURCE-STEP-CHECKLIST.csv`: separate
  source-to-UnrealDB mapping. This is not part of the Epic-only reference.

Regenerate the pilot directly on DEV with:

```powershell
powershell -ExecutionPolicy Bypass -File catalog/bin/build-epic-ue427-source-handbook.ps1
```

This runs SHA-256 and exact-line checks before generating Markdown, HTML,
three Mermaid diagrams, one editable multi-tab draw.io diagram and a PDF.
The PDF is produced from generated HTML with the installed Calibre converter.

**Status:** the first UE4.27.2 `VerifyImport` trace is an **initial source
path only**, not a complete UE4.27.2 package-format handbook and not an
acceptance of any existing PHP implementation. The dependencies in its trace
must be expanded before calling that execution path complete.

## UE4.27.2 source-only reference sections

- [Package loader and serialized field layout](ue4-4.27.2/package-loading/README.md):
  nine sequence diagrams, a selected header true/false graph, exact source
  field and version references, and a print-ready PDF. **Incomplete**;
  genuinely untraced called functions are listed explicitly.
- [VerifyImport source paths](ue4-4.27.2/verifyimport/source-path.md):
  wrapper, inner operation and package-load lambda. Also **incomplete**.

The package-handbook source-only regeneration command is:

```powershell
powershell -ExecutionPolicy Bypass -File catalog/bin/build-epic-ue427-package-handbook.ps1
```

The separate source-to-UnrealDB implementation checklist is in
`catalog/compliance/UE427-LOADER-EPIC-STEP-MAPPING.csv`. It never becomes
an input for Epic reference document generation.

## Future per-release handbook format

Produce a browsable Markdown/HTML source reference, an editable graph file and
a print-friendly PDF **from the same source-controlled content**. Version
numbers and steps must not be manually retyped into an independently authored
PDF. Game-specific differences belong to their own pinned source branches.
