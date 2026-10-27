bp /1 1010146a ".echo G303_C98_ORIGINAL_BAD10_ARCHIVE_CURSOR; dd @esp L5; da poi(@esp+4); r edi; r esi; r ebp; .echo ARCHIVE_STRUCT_DUMP; dd poi(@esp+c) L45; .echo FIND_PHYSICAL_EXPECTED_CURSOR_24238; s -d poi(@esp+c) L300 00005eae; .echo FIND_SERIAL_EXPORT_END_24253; s -d poi(@esp+c) L300 00005ebd; kb 7; q"
g
q
