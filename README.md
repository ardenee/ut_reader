# UT Reader

UT Reader is a browser-based PHP project for inspecting Unreal package files from multiple Unreal Engine generations.

The project is currently focused on readable package inspection rather than full extraction or editing. It parses the package header, name table, import table, export table, generation data, object references, offsets, flags, and selected export/property data where supported.

> **Status:** Active development. The viewers are useful for inspection and parser debugging, but the project is not yet a stable library or finished application.

## Current Viewers

The main supported entry points are the version-specific folders:

| Folder | Viewer | Current purpose |
|---|---|---|
| `UE1/` | `UE1.php` | UE1 / Unreal Tournament era package inspection |
| `UE2/` | `UE2.php` | UE2 / UE2.5 package inspection |
| `UE3/` | `UE3.php` | UE3 / UT3 package inspection, including compressed package handling where supported |
| `UE4/` | `UE4.php` | UE4 `.uasset` / `.umap` package summary, table, and export map inspection |

Each viewer has the same general layout:

- **Package**: package summary, decoded flags, raw header data, offsets, validation notes, and version-specific extra information.
- **Content**: quick list of export/content entries.
- **Externs**: external/import reference view.
- **Tables**: names, imports, exports, and generations.

Raw export/import grids and raw header data are collapsed by default so the normal package summary remains readable.

## Current Features

- Upload/open package files through the browser.
- Display package summary fields in a consistent layout across UE1, UE2, UE3, and UE4 viewers.
- Display GUIDs in dashed `XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX` form.
- Display validation/notes only when there is something useful to report.
- Decode and display package flags where currently supported.
- Parse and display name, import, export, and generation tables.
- Link object/name references between table rows.
- Display export tree/details and property data where the parser currently supports it.
- Display collapsed **Raw Header Data** built from bytes read from the loaded file, not from derived UI-only values.
- Show unparsed summary/header bytes as raw hex when the viewer detects bytes in the header area that have not yet been assigned to a known field.
- UE4 viewer shows additional UE4-specific information such as engine version details, custom versions, asset registry offset, bulk data start, preload dependency offset, chunk IDs, and `.uexp` sidecar status.

## Raw Header Data

The raw header table is intended for parser development and format comparison.

It shows:

- File offset.
- Field size.
- Field name.
- Field type.
- Decoded value.
- Raw hex bytes.
- Notes for version-gated or assumed fields.

The raw header table should only contain values read from the package file header/summary bytes. It should not contain helper or derived UI fields such as filesystem paths, parser state, guessed layout names, or sidecar-file checks.

For UE1/UE2/UE3, version and licensee values are shown as the packed header value where appropriate, because those values are stored together in one raw field.

For UE4, unversioned packages may have raw version values of zero. The viewer may still show an assumed UE4 version in the normal package summary so table parsing can continue. That assumed value is noted separately and should not be treated as a raw file value.

## Supported / Target File Types

Current target package types include:

- UE1-style packages such as `.u`, `.utx`, `.umx`, `.uax`, `.unr`.
- UE2/UE2.5 packages such as `.ut2`, `.u`, `.utx`, `.uax`, `.umx`.
- UE3 packages such as `.ut3` and `.upk`.
- UE4 packages such as `.uasset` and `.umap`.

Support is parser-dependent and still being expanded. A file opening successfully does not mean every export payload or property type is fully decoded yet.

## Runtime Requirements

- PHP 8.2 or newer recommended.
- A PHP-capable web server, such as Synology Web Station, Apache, nginx + PHP-FPM, or a local PHP development server.
- Writable `uploads/` folder inside each viewer folder that will accept browser uploads.
- Optional LZO support for UE3 compressed packages that use LZO compression.

Example writable upload folders:

```text
UE1/uploads/
UE2/uploads/
UE3/uploads/
UE4/uploads/
```

On Linux/Synology, make sure the web server user can write to the relevant upload folder.

## Basic Usage

1. Pull or clone the repository onto a PHP-capable web server.
2. Ensure the relevant `uploads/` folder exists and is writable.
3. Open the correct viewer for the package type:
   - `/UE1/UE1.php`
   - `/UE2/UE2.php`
   - `/UE3/UE3.php`
   - `/UE4/UE4.php`
4. Upload or select a package file.
5. Review the Package tab first.
6. Use the Tables tab to inspect names, imports, exports, and generations.
7. Expand Raw Header Data only when comparing header layouts or debugging parser offsets.

## UE4 Notes

UE4 packages may be split across `.uasset`, `.uexp`, and bulk data files. The viewer currently opens `.uasset` / `.umap` files and checks for a matching `.uexp` sidecar where relevant.

The UE4 Package tab includes:

- legacy file version values,
- UE4 version / assumed version note for unversioned packages,
- package flags,
- folder name,
- counts and offsets,
- raw package summary data,
- engine/custom version details,
- asset registry and bulk data offsets,
- preload dependency information,
- `.uexp` pair status.

The viewer is primarily an inspection/debugging tool. Full UE4 export object decoding is still limited.

## UE3 Compression / LZO Notes

Some UE3 packages use compression. LZO-compressed packages require LZO support.

The current project may use native LZO through PHP FFI when available, with fallback code where supported. Native LZO is preferred because it is faster and more reliable.

Do not commit local native LZO binaries to GitHub. They are platform-specific.

Common local library names that should stay ignored include:

```text
liblzo2.so
liblzo2.so.*
liblzo2-2.dll
lzo2.dll
```

## Current Limitations

- This is an inspection/debugging project, not a complete Unreal package editor.
- Not every export payload is decoded.
- Not every property type is fully interpreted.
- Some version-gated package header fields may still need refinement.
- UE4 unversioned package parsing relies on an assumed UE4 version for table parsing.
- `.uexp` handling is currently for sidecar detection and serial preview support, not full object deserialization.
- Raw header parsing is used to expose mismatches between the reader and real package data, so some rows may intentionally show undecoded bytes until the parser is improved.

## Development Notes

The repository is being actively cleaned and standardized.

Current development focus:

- keeping UE1/UE2/UE3/UE4 viewer layouts consistent,
- improving raw header visibility without mixing in derived UI values,
- aligning header parsing with Unreal Engine source layouts,
- improving export/import/property display accuracy,
- reducing old experimental one-off viewer scripts in favour of the `UE#/UE#.php` viewers.

When adding parser fields, prefer this rule:

```text
Raw Header Data = bytes actually read from the file header/summary.
Normal Package Summary = interpreted, derived, or user-friendly display values.
```

Do not silently skip unknown header bytes. If a header byte range is valid but not decoded yet, show it with offset, size, and raw hex until it can be named correctly.

## Security and Privacy Notes

Before committing files to this repository, check that no private data is included.

Avoid committing:

- uploaded package files,
- personal data,
- credentials,
- API keys,
- local configuration files,
- native binary libraries such as `.dll` or `.so` files,
- large generated output files,
- temporary test data,
- logs containing private paths or usernames.

## License

No license has been specified yet. Add a license before publishing a stable release or accepting external contributions.
