r $t0=0
bp Core+0x3a770 ".if (poi(@ecx+14)==0n633) {r $t0=poi(@esp+4); .echo T009_227K_TARGET_ENDSTATE; gc} .else {gc}"
bp Core+0x73d60 ".if (@$t0!=0){.echo T009_227K_BADIMPORT_ORIGINAL_APPTHROW; r; du poi(@esp+4); dd @$t0+1c L2; dd poi(@$t0+1c)+20 L6; kb 9; q} .else {gc}"
bp Core+0x6a860 ".if (@$t0!=0){.echo T009_227K_BADIMPORT_ORIGINAL_LOGF; r; du poi(@esp+4); dd @$t0+1c L2; dd poi(@$t0+1c)+20 L6; kb 9; q} .else {gc}"
bp Core+0x6a930 ".if (@$t0!=0){.echo T009_227K_BADIMPORT_ORIGINAL_LOGF_ENAME; r; du poi(@esp+8); dd @$t0+1c L2; dd poi(@$t0+1c)+20 L6; kb 9; q} .else {gc}"
g
q
