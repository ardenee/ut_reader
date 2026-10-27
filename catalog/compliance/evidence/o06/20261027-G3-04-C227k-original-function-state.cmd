r $t0=0
bp Core!UFunction::Serialize+0x1be ".if (poi(@edi+0x18) != 0) {.echo G304_C227K_FUNCTION_POSTLOAD; dd @edi+0x10 L7; dd @edi+0xb8 L6; r $t0=@$t0+1; }; .if (@$t0>=0n400) {q} .else {gc}"
bp Core!UState::Serialize+0x1ae ".if (poi(@edi+0x18) != 0) {.echo G304_C227K_STATE_POSTLOAD; dd @edi+0x10 L7; dd @edi+0xb8 L7; r $t0=@$t0+1; }; .if (@$t0>=0n400) {q} .else {gc}"
g
q
