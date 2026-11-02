r $t0=0
bp 101184a0 ".if (poi(@esi+0x10) != 0) {.echo G304_C227I_FUNCTION_POSTLOAD; dd @esi+0x10 L5; dd @esi+0x70 L4; r $t0=@$t0+1; }; .if (@$t0>=0n400) {q} .else {gc}"
bp Core!UState::Serialize+0x6b ".if (poi(@esi+0x10) != 0) {.echo G304_C227I_STATE_POSTLOAD; dd @esi+0x10 L5; dd @esi+0x70 L6; r $t0=@$t0+1; }; .if (@$t0>=0n400) {q} .else {gc}"
g
q
