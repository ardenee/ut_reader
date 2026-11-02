bp Core!UField::Serialize+0x83 ".if (poi(@edi+0xc) != 0) { .printf \"G3T074_FIELD idx=%d this=%p linker=%p super=%p next=%p\\n\", poi(@edi+0x14), @edi, poi(@edi+0xc), poi(@edi+0x30), poi(@edi+0x34); .if (poi(@edi+0x30) != 0) { .printf \"G3T074_SUPERTARGET idx=%d linker=%p\\n\", poi(poi(@edi+0x30)+0x14),poi(poi(@edi+0x30)+0xc); }; .if (poi(@edi+0x34) != 0) { .printf \"G3T074_NEXTTARGET idx=%d linker=%p\\n\", poi(poi(@edi+0x34)+0x14),poi(poi(@edi+0x34)+0xc); } }; gc"
bp Core!UProperty::Serialize+0x97 ".if (poi(@esi+0xc) != 0) { .printf \"G3T074_PROPERTY idx=%d this=%p linker=%p dim=%d flags=%08x cat=%08x repo=%08x\\n\", poi(@esi+0x14), @esi, poi(@esi+0xc), poi(@esi+0x3c), poi(@esi+0x44), poi(@esi+0x60), poi(@esi+0x4c); }; gc"
sxe -c ".echo G3T074_NATIVE_AV; r; kb 5; q" av
g
q
