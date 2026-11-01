.reload /f Core.dll
bp Core+0x69a91 ".printf \"T073_V432_ARRAY idx=%d linker=%p inner=%p\\n\",poi(@esi-0x48),poi(@esi-0x4c),poi(@esi); .if (poi(@esi)!=0){.printf \"T073_V432_INNER idx=%d linker=%p\\n\",poi(poi(@esi)+0x14),poi(poi(@esi)+0x10);};gc"
g
q
