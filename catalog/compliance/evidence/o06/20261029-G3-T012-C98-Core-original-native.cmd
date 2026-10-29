r $t0=0
bp Core+0x12931 ".echo G3_C98_NATIVE_CORE_UClass_REFS;dd @esi+14 L1;dd @esi+7c L12;.echo G3_C98_DEP_RAW;dd poi(@esi+98) L6;.echo G3_C98_DEP0_CLASS_OBJECT_INDEX;dd poi(poi(@esi+98))+14 L1;.if (poi(@esi+9c)>1) {.echo G3_C98_DEP1_CLASS_OBJECT_INDEX;dd poi(poi(@esi+98)+c)+14 L1};r $t0=@$t0+1;.if (@$t0==2) {q} .else {gc}"
g
q
