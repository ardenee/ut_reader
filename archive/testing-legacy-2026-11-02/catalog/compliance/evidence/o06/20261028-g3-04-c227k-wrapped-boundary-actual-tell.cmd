sxe av
r $t0=0
r $t1=0
bp Core+0x611ff ".if (@eax >= 0n190) {.printf \"G304_ORIGINAL_227K_WRAPPED_TELL_RETURN native=%u attempted_length=%u export_end=%u\n\", @eax, poi(@ebp+0xC), poi(@edi+0x20);};gc"
bp Core+0x61208 ".if (@eax > poi(@edi+0x20)) {r $t0=@$t0+1; .printf \"G304_ORIGINAL_227K_EXPORT_CHECK_DENIES_READ attempted_end=%u bound=%u length=%u tell_before=%u\n\", @eax, poi(@edi+0x20), @ebx, @eax-@ebx;kb 5;};gc"
bp Core+0x6129f ".if (@ebx == 2) {r $t1=@$t1+1;.echo G304_ORIGINAL_227K_2BYTE_READ_WAS_ALLOWED;};gc"
g
.echo G304_ORIGINAL_227K_WRAPPED_ARCHIVE_CHECK_FINISHED
r @$t0
r @$t1
q
