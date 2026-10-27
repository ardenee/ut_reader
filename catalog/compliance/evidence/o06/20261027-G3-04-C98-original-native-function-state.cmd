r $t0=0
bp 10114359 ".echo G304_C98_UFunction_NONNET; dd @edi+0x10 L3; dd @edi+0x60 L4; r $t0=@$t0+1; .if (@$t0>=100) {q} .else {gc}"
bp 1011430b ".echo G304_C98_UFunction_NET; dd @edi+0x10 L3; dd @edi+0x60 L4; r $t0=@$t0+1; .if (@$t0>=100) {q} .else {gc}"
bp 10114b69 ".echo G304_C98_UState_LOADED; dd @edi+0x10 L3; dd @edi+0x60 L7; r $t0=@$t0+1; .if (@$t0>=100) {q} .else {gc}"
bp 10114bbe ".echo G304_C98_UState_LOADED; dd @edi+0x10 L3; dd @edi+0x60 L7; r $t0=@$t0+1; .if (@$t0>=100) {q} .else {gc}"
g
q
