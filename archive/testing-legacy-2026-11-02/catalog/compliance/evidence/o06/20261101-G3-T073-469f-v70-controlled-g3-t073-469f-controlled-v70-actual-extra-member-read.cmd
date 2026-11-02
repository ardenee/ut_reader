bp Core!UArrayProperty::Serialize+0x3a ".if (poi(@edi+0x14)==0x15fb) { .printf \"T073_469F_ALTERNATE_VERSION_BEFORE id=%d archiveVersion=%d isLoading=%d isSaving=%d isError=%d\\n\",poi(@edi+0x14),poi(@esi+4),poi(@esi+0x10),poi(@esi+0x14),poi(@esi+0x18);ed @esi+4 0x46;};gc"
bp Core!UArrayProperty::Serialize+0x96 ".if (poi(@edi+0x14)==0x15fb) { .printf \"T073_469F_ORIGINAL_EXTRA_READ_START id=%d archiveVer=%d memberBefore=%d\\n\",poi(@edi+0x14),poi(@esi+4),poi(@edi+0x64);};gc"
bp Core!UArrayProperty::Serialize+0xa3 ".if (poi(@edi+0x14)==0x15fb) { .printf \"T073_469F_ORIGINAL_EXTRA_READ_END id=%d archiveVer=%d memberAfter=%d\\n\",poi(@edi+0x14),poi(@esi+4),poi(@edi+0x64);q;};gc"
g
q
