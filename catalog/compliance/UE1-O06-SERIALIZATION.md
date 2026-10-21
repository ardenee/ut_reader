# UE1 O06 — object/property serialization acceptance

Updated 2026-10-21. **IMPLEMENTING — NOT ACCEPTED.**

This operation is concerned with bytes serialized by each source UE1 generation's object/property serializers. It is distinct from O07, which will validate/extract the pixel, audio, geometry or external/bulk contents that an O06 serializer locates, and O10, which checks actual runtime engine loading and end-to-end behaviour. Successful header parsing is NOT object/property acceptance.

## Fixed source-specific work and evidence

| Family | Required O06 behaviour | Current verified evidence | Acceptance state |
|---|---|---|---|
| Original resource v9/v10 (1996) | Decode native 32-bit per-resource header, class-specific header fields and object properties/data semantics, preserving process pointers as nonportable | Native common headers/physical header+data digests tested 20/20, UDatabase Num/Max and UPalette 1024-byte fixed record tests 12/12; staging stores source-typed native fields, never invented modern objects | **OPEN:** additional derived UResource header/data schemas beyond UDatabase; original reference-fixup callbacks |
| Original Unrealfile v22/v23 (1997) | Decode UObjectBase, FExecStackMain header prefix, UClass::SerializeBin per-class object members, class-specific SerializeData, and 1-based references | Source-defined object bases 35/35; stack prefixes 18/18; class schema and binary SerializeBin values 18/18; class/data byte ranges SHA-256; CLI and native unverified staging | **OPEN:** other original class-specific SerializeData and inheritance/external UClass schemas; the source-defined binary test includes Int, Bool and UObject references, not every original field type |
| Classic v35–69 (1998–1999) | Version-gated stack/db fields, FPropertyTag metadata, array sizes/indices, scalar/struct values, and source-defined native class serializers | All 92 SHA-pinned corpus files: 2,465/2,465 exports; 15,475 serialized tags; 805 palettes, 1,228 textures (including derived), 396 sounds, 22 fonts, 14 packages; no opaque tags or native serializer failures; malformed payload tests 9/9 | **PASSED for this named corpus only**, not all UE1 classes or arbitrary game-specific packages |
| Production diagnostics / unverified early staging | Surface exact decoded fields and explicit unsupported states, without successful runtime flags | CLI shows classic tagged/native preview and original range/stack records. Original staging/MySQL tests 29/29 and 16/16 | **PASS** for verified routes |

## Critical source-defined differences

- 1996 v9/v10 uses FGlobalResourceManager raw UResource structures. Resource data is not a classic UE1 tagged-property stream.
- 1997 v22/v23 uses UObject::SerializeHeader: FExecStackMain (optional node/code fields) then UClass::SerializeBin(PROPBIN_PerObject). The reader now extracts UClass::SerializeHeader member schema, FProperty descriptor definitions and native instance values where the class schema is locally available. Missing external/inherited class schemas or unsupported class-specific SerializeData remain explicitly unresolved.
- Classic Unreal v1.200 UObject::Serialize serializes GObj.TempNum/TempMax when version<40, MainFrame for version<47 even when RF_HasStack is clear; later versions serialize a frame only when flagged. Pre-v57 state and pre-v58 group FName fields follow tagged properties.
- Version 35 object property data uses a compact package reference in the genuine DProj corpus even when the tag size field is 4; this is recorded as a version-scoped corpus exception, not asserted to be a native-runtime trace.
- v400 FPropertyTag type/size/array rules and UTexture/UFont/USound/UPalette/FireTexture class serializers are implemented for records represented in the pinned corpus. Unprocessed color/audio/mip bulk bytes belong to O07, not a false property decode.

## Repeatable commands

`php catalog/bin/verify-ue1-classic-tagged-properties.php` — pinned 92-file property/class-native corpus, counts and SHA-256; reject loss of existing coverage.

`php catalog/bin/verify-ue1-o06-payload-negatives.php` — genuine-file mutation tests; CLI/staging source scope.

`php catalog/bin/verify-ue1-original-resource-headers.php` and `verify-ue1-original-resource-database.php` — v9/v10 header/digest and native UDatabase Num/Max/palette tests (20/20 + 12/12).

`php catalog/bin/verify-ue1-original-unrealfile-object-bases.php`, `verify-ue1-original-unrealfile-stack-prefix.php` and `verify-ue1-original-unrealfile-class-schemas.php` — v22/v23 independent class/data ranges, FExecStackMain, UClass FProperty schema and decoded instance field values (35/35 + 18/18 + 18/18).

Do **not** mark O06 accepted until the outstanding 1996 derived-class and 1997 binary class-member serialization are implemented and acceptance tests demonstrate their decoded values. Do not infer genuine early-format binary evidence from text .u class declarations in source trees. Any additional genuine corpus classes discovered must be audited under O06 (not as new operation IDs).
