r $t0=0
bp 10110c88 ".if (poi(@esi+0x14)==1){.echo G3_T007_C98_VECTOR_LINKS;dd @esi+0x10 L16;.if (poi(@esi+0x38)!=0){.echo G3_T007_C98_VECTOR_CHILD_ID;dd poi(@esi+0x38)+0x10 L2;};.if (poi(@esi+0x2c)!=0){.echo G3_T007_C98_VECTOR_NEXT_ID;dd poi(@esi+0x2c)+0x10 L2;};r $t0=@$t0+1;};.if (poi(@esi+0x14)==0){.echo G3_T007_C98_CLASS_OBJECT_LINKS;dd @esi+0x10 L16;.if (poi(@esi+0x34)!=0){.echo G3_T007_C98_CLASS_SCRIPTTEXT_ID;dd poi(@esi+0x34)+0x10 L2;};.if (poi(@esi+0x38)!=0){.echo G3_T007_C98_CLASS_CHILD_ID;dd poi(@esi+0x38)+0x10 L2;};r $t0=@$t0+1;};gc"
g
.echo G3_T007_C98_LINK_TARGET_CAPTURE_FINISHED
r $t0
q
