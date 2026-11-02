r $t0=0
r $t1=0
r $t2=0
bp Core!UObject::SavePackage "r $t0=1; .echo G304_TINY_NATIVE_SAVE_START; gc"
bp Core!UFunction::Serialize+0x1be ".if (@$t0==1) {r $t1=@$t1+1; .echo G304_TINY_SAVING_UFUNCTION; dd @edi+0x10 L7; dd @edi+0xb8 L6;};gc"
bp Core!UState::Serialize+0x1ae ".if (@$t0==1) {r $t2=@$t2+1; .echo G304_TINY_SAVING_USTATE; dd @edi+0x10 L7; dd @edi+0xb8 L6;};gc"
g
.echo G304_TINY_NATIVE_SAVE_COMPLETED
r @$t1
r @$t2
q
