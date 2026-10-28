sxd eh
r $t0=0
bp Core!ULinkerLoad::Preload+0x139 "r $t0=@$t0+1;.printf \"G304_469F_REAL_COMPILED_FARCHIVE_TELL event=%u native_tell=%u serial_start=%u actual_bytes=%u declared_size=%u\n\",@$t0,@eax,@esi,@eax-@esi,poi(poi(@ebp-0x14)+0x14);gc"
g
.echo G304_469F_NATIVE_PRELOAD_ARCHIVE_TELL_END
r @$t0
q
