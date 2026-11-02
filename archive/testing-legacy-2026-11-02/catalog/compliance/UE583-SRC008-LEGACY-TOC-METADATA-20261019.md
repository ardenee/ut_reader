# UE5.8.3 SRC-008: legacy TOC metadata fixture evidence (2026-10-19)

## Scope

The isolated IoStore foundation fixture now exercises version 2 and version 5 TOC metadata against `Uedb5IoStoreTocReader`. These pre-v8 formats serialize each entry's 32-byte chunk hash and one-byte flags (33 bytes), rather than the v8 20-byte hash, one-byte flags and three-byte padding (24 bytes).

The synthetic legacy fixtures retain the same chunk IDs, compressed blocks, method table and UCAS payload as the passing version-8 case. Assertions require lossless metadata hash decoding and reconstructed content equality. The version-2 fixture additionally requires the source-defined single-partition default. The foundation driver now passes 37 assertions (previously 34); B006 pins the updated driver SHA256 and assertion count.

## Limitations

These are independent expected-value synthetic fixture assertions, **not** an Epic-native decoded-field comparison or an authentic cooked legacy container. SRC-008 remains `partial`, and all other partial denominator units and the 16 end-to-end production workflow acceptance gates remain open. Neither the 18 existing registered operations nor UE5 certification status is promoted by these tests.
