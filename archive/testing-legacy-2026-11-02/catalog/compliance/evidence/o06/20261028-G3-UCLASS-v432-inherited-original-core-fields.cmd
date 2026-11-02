bp Core+0x27b30 ".echo G3_CLASS_NATIVE_7_INHERITED;;dd @esi+14 L1;.echo G3_REF_SUPER;dd @esi+28 L1;.if (poi(@esi+28)!=0) {.echo NATIVE_REFERENT_OBJECT_INDEX;dd poi(@esi+28)+14 L1} .else {.echo NATIVE_NULL_REF};.echo G3_REF_NEXT;dd @esi+2c L1;.if (poi(@esi+2c)!=0) {.echo NATIVE_REFERENT_OBJECT_INDEX;dd poi(@esi+2c)+14 L1} .else {.echo NATIVE_NULL_REF};.echo G3_REF_SCRIPT_TEXT;dd @esi+34 L1;.if (poi(@esi+34)!=0) {.echo NATIVE_REFERENT_OBJECT_INDEX;dd poi(@esi+34)+14 L1} .else {.echo NATIVE_NULL_REF};.echo G3_REF_CHILDREN;dd @esi+38 L1;.if (poi(@esi+38)!=0) {.echo NATIVE_REFERENT_OBJECT_INDEX;dd poi(@esi+38)+14 L1} .else {.echo NATIVE_NULL_REF};.echo G3_CLASS_INHERITED_END;gc"
sxe -c ".echo G3_NATIVE_UNEXPECTED_AV; kb 7; q" av
g
q
