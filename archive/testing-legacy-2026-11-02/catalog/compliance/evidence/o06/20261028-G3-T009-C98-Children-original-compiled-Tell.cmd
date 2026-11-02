bp /1 1010146a ".echo T009_C98_ORIGINAL_CURSOR_ONLY; da poi(@esp+4); dd @esp+4 L6; .echo T009_ARCHIVE_PTR; dd poi(@esp+10) L8; .echo T009_ARCHIVE_TELL_SLOT; dd poi(@esp+10)+11c L9; .echo T009_CANDIDATE_24224; s -d poi(@esp+10) L300 00005ea0; .echo T009_CANDIDATE_24243; s -d poi(@esp+10) L300 00005eb3; kb 6; q"
sxe -c ".echo T009_UNEXPECTED_AV; r; kb 6; q" av
g
q
