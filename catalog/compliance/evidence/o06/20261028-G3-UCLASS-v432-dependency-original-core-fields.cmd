bp Core+0x27b30 ".echo ORIGINAL_UCLASS_DEPENDENCY_ACTUAL_OBJECT_INDEX; .echo NATIVE_OWNER_EXPORT;dd @esi+14 L1; .echo NATIVE_DEP_COUNT;dd @esi+4c4 L1; .if (poi(@esi+4c4)>0) {.echo FIRST_REAL_DEPENDENCY_EXPORT;dd poi(poi(@esi+4c0))+14 L1}; .if (poi(@esi+4c4)>1) {.echo SECOND_REAL_DEPENDENCY_EXPORT;dd poi(poi(@esi+4c0)+c)+14 L1}; .echo NATIVE_DEP_RECORD_DONE;gc"
sxe -c ".echo ORIGINAL_NATIVE_UNEXPECTED_AV; r; kb 6; q" av
g
q
