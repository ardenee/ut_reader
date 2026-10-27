r $t0=0
bp Core!UFunction::Serialize+0x118 ".if (poi(@edi+0x10) != 0) { .echo G304_T99_469F_UFUNCTION_LOADED; dd @edi+0x10 L3; dd @edi+0x70 L4; r $t0=@$t0+1; }; .if (@$t0>=0n400) {q} .else {gc}"
bp Core!UState::Serialize+0x77 ".if (poi(@edi+0x10) != 0) { .echo G304_T99_469F_USTATE_LOADED; dd @edi+0x10 L3; dd @edi+0x70 L6; r $t0=@$t0+1; }; .if (@$t0>=0n400) {q} .else {gc}"
g
q
