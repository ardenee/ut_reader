bp Core!UField::Serialize+0x83 ".if (poi(@esi+0xc) != 0) { .printf \"T074_ENGINE_FIELD linker=%p idx=%d sup=%p next=%p\\n\",poi(@esi+0xc),poi(@esi+0x14),poi(@esi+0x30),poi(@esi+0x34); };gc"
bp Core!UProperty::Serialize+0x97 ".if (poi(@esi+0xc) != 0) { .printf \"T074_ENGINE_PROPERTY linker=%p idx=%d dim=%d flags=%08x\\n\",poi(@esi+0xc),poi(@esi+0x14),poi(@esi+0x3c),poi(@esi+0x44); };gc"
bp Core!UStruct::Serialize+0x24c ".if (poi(@edi+0xc) != 0) { .printf \"T074_ENGINE_STRUCT linker=%p idx=%d child=%p script=%p line=%d textpos=%d\\n\",poi(@edi+0xc),poi(@edi+0x14),poi(@edi+0x40),poi(@edi+0x3c),poi(@edi+0x7c),poi(@edi+0x78); };gc"
g
q
