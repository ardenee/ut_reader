r $t0 = 0
bp 10110c88 ".if (poi(@esi+0x10) != 0) { .echo G303_C98_MAY1998_UStruct_NATIVE_LOADED; dd @esi+0x10 L2; dd @esi+0x34 L10; .if (poi(@esi+0x50) > 0) { .if (poi(@esi+0x50) <= 0n48) { db poi(@esi+0x4c) Lpoi(@esi+0x50) } .else { db poi(@esi+0x4c) L0n48 } } ; r $t0 = @$t0 + 1; .if (@$t0 >= 0n100) { q } .else { gc } } .else { gc }"
g
q
