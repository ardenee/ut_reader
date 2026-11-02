bp Core+0x27ab3 ".echo ORIGINAL_CLASSFLAGS_BEFORE_ARCHIVE; dd @esi+488 L2; dd @ebx+444 L1; dd poi(@ebx+444)+3c L1; gc"
bp Core+0x27ac3 ".echo ORIGINAL_CLASSFLAGS_AFTER_ARCHIVE; dd @esi+488 L2; dd @ebx+444 L1; dd poi(@ebx+444)+3c L1; r; kb 9; q"
sxe -c ".echo UNEXPECTED_AV; r; kb 7; q" av
g
q
