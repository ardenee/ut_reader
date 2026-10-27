# G3 live closure ? 27 October 2026

**Full G3 contracts: 1/16. Contracts with original compiled native subproofs: 10/16. Distinct original compiled native subproofs: 11.**

G3-01 source selection is accepted under the clarified cumulative game-source inheritance rule. The remaining 15 method/behavior gates retain their strict source/real native parity criteria. Older G3 acceptance matrix is a pre-execution plan and its PLANNED entries must not be mistaken for the live closure status.

| Contract | Native subproofs | Decisive remaining requirement |
|---|---:|---|
| G3-01 Exact Epic C98/T99/C227 Core source lineage + version gates | **ACCEPTED: 87/87 DEV** | CLOSED: eight SHA-pinned Unreal source revisions, UT99 v400/v432 chain; UE1 engine and cumulative overrides with later absent CPP inherited; wrong-game and ambiguous provenance rejected. See source-chain manifest and focused G3-01 verifier. Native loaded-value acceptance is G3-15. |
| G3-02 Complete class/property serializer symbols, inherited paths and source exclusions | 1 | Map only actually changed source header/inline/new serializer methods across revisions; inherited absent game CPP already resolved; 14/57 ABI mismatch alone is not a missing source blocker. |
| G3-03 UField and UStruct | 1 | Original compiled UStruct script/child/load and malformed cases for C98/T99/C227; 2012 UField reference branch complete only. |
| G3-04 UState and UFunction | 0 | Original compiled UState/UFunction flags, script, version exceptions and malformed cursor for each profile. |
| G3-05 UClass serialization and default objects | 0 | Original compiled UClass default-object construction, values, Config/Localized effects and error paths across C98/T99/C227. |
| G3-06 UConst and UEnum | 4 | C98/v432/C227 UConst/UEnum negative archives, LOAD/SAVE and version gates; compiled 2012 C227i only covers observed objects. |
| G3-07 UProperty base metadata | 2 | ElementSize/category/net/error behavior across all three build profiles, including unobserved original property exports. |
| G3-08 Scalar derived properties and String/Str version changes | 2 | Bool, Byte, Int, Float, Name, String, Str and later C227 scalar type/version and malformed paths, original compiled behavior. |
| G3-09 UObjectProperty and UClassProperty references | 2 | ObjectProperty/ClassProperty original T99 and C227 referents, invalid type/redirect, LOAD/SAVE and failure cursor. |
| G3-10 UStructProperty nested layouts | 1 | UStructProperty nested SerializeBin/offsets/imported fields and negative nested errors for all profile builds. |
| G3-11 UArrayProperty, UFixedArrayProperty and UMapProperty | 1 | Native array/fixed-array/map loaded values and source-defined exclusion/invalid-count cases across each distinct build. |
| G3-12 Source script expression, bytecode and fixups | 0 | Native script opcode fixups and bytecode error cursor for C98 and C227; reuse verified v432 G5. |
| G3-13 Tagged property values, defaults, config and localized | 0 | Native loaded tagged/default/config/localized state for C98 and C227, source-specific defaults and invalid cases. |
| G3-14 Source version branches and exact malformed FArchive errors | 1 | All unique source-defined malformed and version failure branches beyond two verified May1998 reference errors. |
| G3-15 Original compiled native loaded-value and genuine-file parity | 11 | Native parity only for source-defined distinct serializers/version-error branches and genuine observed objects; unrelated 2012 vs later C227 ABI diagnostic is not an absent game-source blocker. |
| G3-16 One independent whole-G3 gate / DEV release / PRD SHA | 0 | Independent one-off whole-G3 gate after G3-01 through G3-15 meet their full acceptance requirements. |

## Evidence (original compiled engine, subset-specific)

- may-const-enum: catalog/compliance/UE1-O06-G3-C98-MAY1998-ORIGINAL-NATIVE-LOADED.json (SHA256 5f8a8a3902e36f7a2b51e7b29e6f55bca5eed4dbd820d8d547070dc8b80b58d2)
- july-const-enum: catalog/compliance/UE1-O06-G3-C98-RETAIL-ORIGINAL-NATIVE-LOADED.json (SHA256 64c2131efc660bb482f3866d46290769eb48ac24c34e7bde5f2fa9814af91b53)
- c227i-const: catalog/compliance/UE1-O06-G3-C227I-UCONST-NATIVE-LOADED.json (SHA256 1146e08561fb621bf8e404f6fa52bf29bac0188b43cb2ac1906c476fb13a5064)
- c227i-enum: catalog/compliance/UE1-O06-G3-C227I-UENUM-NATIVE-LOADED.json (SHA256 5cddcc834996fe0cc66bca18e747e817f2e484096a2c29f6b24813919458221c)
- may-property1536: catalog/compliance/UE1-O06-G3-C98-MAY1998-UPROPERTY-1536-IDENTITIES.json (SHA256 9fa3da97724615df240693c6b0938028c926ee875bc888b7fd003b894c5c8d67)
- may-string66: catalog/compliance/UE1-O06-G3-C98-MAY1998-USTRING-66-LOADED.json (SHA256 fdef03ff417cda89fca8af115279116a833f4ac84242db9688e2cec2073c23bf)
- may-ref600: catalog/compliance/UE1-O06-G3-C98-MAY1998-600-REFERENCE-LOADED.json (SHA256 50139b8dd8a0faa0afdfa9a8ada825ad6bd76af9e7d185139d7adf92eebe452b)
- may-malformed2: catalog/compliance/UE1-O06-G3-C98-MAY1998-TWO-NATIVE-MALFORMED-CURSORS.json (SHA256 643f1d0d4e0ed77407d8f1845f39306a9c75005cbacfaae615c5d8830f19727f)
- c227i-property668: catalog/compliance/UE1-O06-G3-C227I-UPROPERTY-668-EXACT-IDENTITIES.json (SHA256 3b3d4e779644fe5f620df60e4cc94c6b1969f746ebe2b85e76e094f734304996)
- c227i-field915: catalog/compliance/UE1-O06-G3-C227I-UFIELD-915-EXACT-NATIVE-REFERENCES.json (SHA256 da9d0616f9e65670fc9e8d1ea1007b403384bb6e3b409b8e6a3da3395051777c)

## Exit rule

Never promote a contract just because a previous native positive sample passed, never rerun SHA-identical sealed proofs, and do not treat the 2012 C227i ABI as proof of the later C227 source. Complete actual original source/native branches before marking each of 16 accepted.

## G3-01 complete source-selection decision (2026-10-27)

**ACCEPTED 87/87 DEV**: exact cumulative original Unreal game-source chain v0.82/v0.83/v0.84a/v0.86x/v0.867/v1.200/v1.224/v1.227, original v0.84a changes remain until actual newer source changes, v1.200 last present classic Core serializer body inherited into v1.224/227, changed later headers remain true source deltas. UT99 v432 inherits v400 game core unless actual v432 changes. All eight source snapshots plus both UT99 baseline/replacement SHA pinned in UE1-O06-G3-UNREAL-CHRONOLOGICAL-SOURCE-CHAIN.json. Explicit version, wrong-game, 69/227 licensee, ambiguous and deprecated v400 alias checks included. Compiled loaded values, newer header-specific method implementations and archive errors remain distinct G3-02..15 work. Missing game CPP and differing later Core.lib are NOT G3-01 blockers. Independent focus transcript: catalog/compliance/evidence/o06/20261027T102503120Z-98b1e087-DEV-verify-ue1-o06-g3-01-chronological-source-inheritance.php.txt.


## G3-02 newly introduced Unreal v227 subclass inheritance implementation (2026-10-27)

Original v227 Core/Inc/UnType.h newly defines UPointerProperty and UAnyProperty with **NO Serialize(FArchive&) override**: their on-disk declarations persist the inherited UE1 UField -> UProperty serialized members and add no subclass metadata bytes. The source expressly maps their 4-bit tagged instance-value type aliases to NAME_IntProperty and NAME_VectorProperty, respectively, which does NOT make their runtime SerializeItem behavior identical to normal Int/Vector properties. Production CatalogUE1ClassicPropertyNativeReader now recognizes both classes **only for selected unreal-v227**, preserves exact tagged aliases in declaration metadata and keeps runtime SerializeItem unverified. Both other selected game sources reject those class identities. Critically, original v227 UButtonProperty **DOES declare** Serialize(FArchive&) and thus cannot be treated as inherited when its method body is unavailable: CatalogUE1ClassicNativeObjectReader now returns an explicit source-defined override-unverified status even if the native byte tail is zero. Genuine 2012 227i Core.u/Engine.u/Editor.u/UMenu.u contained no instances of the new three property classes in the selected stock commandlet corpus; one new source-SHA-based synthetic version69/licensee227 fixture checker covers inherited positive, wrong game and extra byte, plus explicit override refusal: **20/20 DEV scoped PASS**. Source inherited classes are now production implemented; UButton explicit implementation and remaining changed v227 source declarations are still G3-02 work. Full G3 remains **1/16**. Source SHA evidence is part of UE1-O06-G3-UNREAL-CHRONOLOGICAL-SOURCE-CHAIN.json.


## G3-02 / G3-11 original 2012 C227i actual compiled new source subclasses (2026-10-27)

v1.224 Unreal game source introduces UStrProperty, UArrayProperty, UFixedArrayProperty, UMapProperty; v1.227 retains them. Actual original 2012 227i compiled Core.dll disassembly establishes UStr inherited UProperty only, UArray inherited then Inner, UFixedArray inherited then Inner+Count, UMap inherited then Key+Value. Four native UArrayProperty::Serialize original Core.u indices 662/667/669/746 loaded real original Inner pointers to source exports 928/922/917/843, or saved refs 929/923/918/844; all four exactly match the current production reader. True original native x86 debugger transcripts, original DLL/UCC/Core.u and three source UnType.h revisions SHA pinned in UE1-O06-G3-C227I-ARRAY-FOUR-EXACT-ORIGINAL-NATIVE-INNER.json. Independent NEW one-shot DEV scoped verifier 73/73 PASS; no old 668 native UProperty base trace replay or production decoder changes required. No UFixedArray or UMap actual loaded instances on the original UCC startup; native disassembly is evidence of call order ONLY, not genuine loaded-value certification. G3-02, G3-11, G3-15 remain OPEN; full G3 stays 1/16.
