bp Core+0x38a14 ".printf \"T072_NATIVE_ENUM idx=%d linker=%p count=%d\\n\",poi(@esi-0x20),poi(@esi-0x24),poi(@esi+8); .for (r @$t0=0; @$t0<poi(@esi+8); r @$t0=@$t0+1) { .printf \"T072_NATIVE_ENUM_NAME idx=%d ord=%d global=%d: \",poi(@esi-0x20),@$t0,poi(poi(@esi)+4*@$t0); da poi(poi(1018f910)+4*poi(poi(@esi)+4*@$t0))+0xc L100; };gc"
g
q
