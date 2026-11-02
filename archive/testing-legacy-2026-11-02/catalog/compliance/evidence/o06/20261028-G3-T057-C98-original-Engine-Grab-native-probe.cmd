r $t0=0
bp 10110c88 ".if (poi(@esi+0x14)==0n1613) { .echo T057_C98_ORIGINAL_ENGINE_EXPORT1613; dd @esi+0x10 L2; dd @esi+0x34 L10; db poi(@esi+0x4c) L0n30; r $t0 = @$t0+1; q } .else {gc}"
sxe -c ".echo T057_UNEXPECTED_AV; r; kb 10; q" av
g
q
