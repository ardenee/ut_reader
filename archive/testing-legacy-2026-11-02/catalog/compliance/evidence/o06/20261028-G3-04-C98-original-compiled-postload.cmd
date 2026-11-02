r $t0=0
bp 10114359 ".if (poi(@edi+0x14)<=8) {.printf \"G304_C98_NEW_NATIVE_FUNCTION_NONNET linker=%08X index=%d object=%08X\n\",poi(@edi+0x10),poi(@edi+0x14),@edi;dd @edi+0x60 L4;r $t0=@$t0+1;};gc"
bp 10114b69 ".if (poi(@edi+0x14)<=8) {.printf \"G304_C98_NEW_NATIVE_STATE linker=%08X index=%d object=%08X\n\",poi(@edi+0x10),poi(@edi+0x14),@edi;dd @edi+0x60 L7;r $t0=@$t0+1;};gc"
bp 10114bbe ".if (poi(@edi+0x14)<=8) {.printf \"G304_C98_NEW_NATIVE_STATE_ALT linker=%08X index=%d object=%08X\n\",poi(@edi+0x10),poi(@edi+0x14),@edi;dd @edi+0x60 L7;r $t0=@$t0+1;};gc"
g
.echo G304_C98_NEW_ORIGINAL_NATIVE_LOAD_FINISHED
r @$t0
q
