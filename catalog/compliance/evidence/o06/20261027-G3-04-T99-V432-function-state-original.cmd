r $t0=0
bp 10128bdd ".if (poi(@edi+0x10) != 0) {.echo G304_T99_V432_UFUNCTION_POSTLOAD; dd @edi+0x10 L3; dd @edi+0x70 L4; r $t0=@$t0+1;}; .if (@$t0>=0n650) {q} .else {gc}"
bp 10126f8d ".if (poi(@edi-0x80+0x10) != 0) {.echo G304_T99_V432_USTATE_POSTLOAD; dd @edi-0x80+0x10 L3; dd @edi-0x80+0x70 L6; r $t0=@$t0+1;}; .if (@$t0>=0n650) {q} .else {gc}"
g
q
