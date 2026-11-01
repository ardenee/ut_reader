bp Core+0xf3dd ".if (poi(@esi+0x10) != 0) { .printf \"T072_C98_NEW_FIELD linker=%p idx=%d super=%p next=%p\\n\",poi(@esi+0x10),poi(@esi+0x14),poi(@esi+0x28),poi(@esi+0x2c); };gc"
bp Core+0x414d9 ".if (poi(@esi+0x10) != 0) { .printf \"T072_C98_NEW_PROPERTY linker=%p idx=%d dim=%d flags=%08x\\n\",poi(@esi+0x10),poi(@esi+0x14),poi(@esi+0x34),poi(@esi+0x38); };gc"
bp Core+0x10c88 ".if (poi(@esi+0x10) != 0) { .printf \"T072_C98_NEW_STRUCT linker=%p idx=%d child=%p scripttext=%p line=%d textpos=%d scriptnum=%d\\n\",poi(@esi+0x10),poi(@esi+0x14),poi(@esi+0x38),poi(@esi+0x34),poi(@esi+0x48),poi(@esi+0x44),poi(@esi+0x50); };gc"
g
q
