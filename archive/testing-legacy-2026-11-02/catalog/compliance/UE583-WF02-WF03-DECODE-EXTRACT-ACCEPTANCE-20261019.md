# UE5.8.3 workflows 02–03 — decode and extract acceptance

**WF02 read_decode and WF03 extract_import_export accepted within the declared UE5.8.3 classic/Zen/IoStore/PAK metadata and payload-reader scope.** This does not certify live UObject execution, unsupported future engine layout, or encryption without a key.

Epic-native independently matched 27,241 IoStore chunks and 45,751 blocks (360,091 TOC decoded fields); all 11 positive and five negative TOC-layout cases; 100 real Zen packages/6,546 fields and one versioned fixture/38 fields; 140 classic packages/44,840 name/import/export fields; 27,240/27,240 full Satisfactory Zen package reconstructed SHA-256 and length; and 4,761/4,761 PAK11 member metadata and decoded SHA-256. Zero semantic or byte mismatches.

Production paths: `catalog/src/Infrastructure/Metadata/Uedb5IoStoreTocReader.php`, `catalog/src/Infrastructure/Metadata/Uedb5IoStoreCodec.php`, `catalog/src/Infrastructure/Metadata/Uedb5ZenPackageReader.php`, `catalog/lib/CatalogPakModernIndex.php` and `catalog/src/Infrastructure/Metadata/Uedb5Ue5ClassicSnapshotBuilder.php`. Bounded rerun of `verify-uedb5-ue5-iostore-foundation.php`, `verify-uedb5-ue5-zen-package.php`, and `verify-ue5-583-pak11-real-satisfactory.php`: all exit 0. The 27,240-file extraction was not rerun; its retained Epic-native evidence is `UE583-EPIC-IOSTORE-CONTENT-DIFFERENTIAL-20261019.md`. Native PAK evidence is `UE583-PAK-EPIC-INDEPENDENT-DIFFERENTIAL-20261019.md`.

Other workflows (upload, persistence, search, backup, repair, resync) are not accepted by these decoder proofs. Source denominator 11/11 independently verified and scoped to UnrealDB's metadata/payload role.
