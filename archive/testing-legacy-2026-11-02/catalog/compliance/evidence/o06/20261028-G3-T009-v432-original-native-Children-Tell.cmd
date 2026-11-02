r $t0=0
bp 10126920 ".if (poi(@ecx+14)==0n634){r $t0=poi(@esp+4); .echo TARGET_ORIGINAL_V432_ENDSTATE_SOURCE; gc} .else {gc}"
bp 10102cfc ".if (@$t0!=0){.echo NATIVE_V432_CHILDREN_BADIMPORT_APPTHROW; du poi(@esp+4); dd @$t0 L6; dd @$t0+444 L2; dd poi(@$t0+444)+3c L5; kb 9; q} .else {gc}"
bp 1010284c ".if (@$t0!=0){.echo NATIVE_V432_CHILDREN_BADIMPORT_LOGF; du poi(@esp+4); dd @$t0 L6; dd @$t0+444 L2; dd poi(@$t0+444)+3c L5; kb 9; q} .else {gc}"
bp 10102ad1 ".if (@$t0!=0){.echo NATIVE_V432_CHILDREN_BADIMPORT_LOGF_ENAME; du poi(@esp+8); dd @$t0 L6; dd @$t0+444 L2; dd poi(@$t0+444)+3c L5; kb 9; q} .else {gc}"
g
q
