bp 10110c88 ".if (poi(@esi+0x14)==0n1613) { .echo T057_C98_ORIGINAL_EDITOR_LOADED_ENGINE_EXPORT1613; dd @esi+0x10 L2; dd @esi+0x34 L10; db poi(@esi+0x4c) L0n30; q } .else {gc}"
bp /1 1010146a ".echo T057_C98_EDITOR_ORIGINAL_COMPILED_ERROR; da poi(@esp+4); kb 8; q"
g
q
