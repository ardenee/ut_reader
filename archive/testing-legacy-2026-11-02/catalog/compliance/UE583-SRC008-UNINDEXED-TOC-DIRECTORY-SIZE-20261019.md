# UE5.8.3 IoStore source discrepancy: unindexed directory-size admission

Pinned Epic implementation: `Engine/Source/Runtime/Core/Private/IO/IoStore.cpp`, `FIoStoreTocResourceView::Read` (directory-index branch around lines 1404–1409; source hash pinned by `UE583-SOURCE-DENOMINATOR.json`).

**Epic:** consumes the directory-index byte section only when `ReadDirectoryIndex` is enabled, the container has the `Indexed` flag, and `Header.DirectoryIndexSize > 0`. It does not reject a header solely for a nonzero directory size on an unindexed container.

**UnrealDB before:** `Uedb5IoStoreTocReader::parse()` threw for `!Indexed && DirectoryIndexSize != 0`, imposing a validation condition absent from the Epic branch.

**Fixed:** only reads directory-index bytes for `Indexed` containers; otherwise proceeds directly to chunk metadata. The bound synthetic fixture sets a nonzero directory index size on an unindexed v8 TOC without inserting index bytes; requires both empty decoded index and successfully reconstructed package content. Full IoStore foundation checks 38/38, updated B006 guard and B001/B002 implementation hashes pass.

This closes this specific source-behavior discrepancy, **not** the entire SRC-008 semantic differential, the other five partial denominator units, or production workflow acceptance. No Satisfactory rescan or production import was performed.
