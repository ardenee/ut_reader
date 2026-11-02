r $t0=0
bp Core!UFunction::Serialize+0x110 ".if (poi(@esi+0x14)<=8) {.printf \"G304_2012_NEW_FUNCTION_LOADED linker=%08X index=%d object=%08X\n\",poi(@esi+0x10),poi(@esi+0x14),@esi;dd @esi+0x70 L4;r $t0=@$t0+1;};gc"
bp Core!UState::Serialize+0x6b ".if (poi(@esi+0x14)==6) {.printf \"G304_2012_NEW_STATE_LOADED linker=%08X index=%d object=%08X\n\",poi(@esi+0x10),poi(@esi+0x14),@esi;dd @esi+0x70 L6;r $t0=@$t0+1;};gc"
g
.echo G304_2012_NEW_METHOD_NATIVE_LOAD_DONE
r @$t0
q
