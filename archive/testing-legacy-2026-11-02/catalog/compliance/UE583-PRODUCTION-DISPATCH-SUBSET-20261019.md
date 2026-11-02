# UE5.8.3 production dispatch subset verification

On 19 October 2026, `catalog/bin/verify-ue5-583-dispatch-links.php` passed (exit 0, `ok=true`, 5/5 checks). It verifies repository source references for dedicated UE5 reader, UE5 classic snapshot builder, separate Zen builder, verified V5 publisher and compact V5 search repository. These are **static dispatch-link assertions**, not runtime route execution, native Epic semantic parity or an exhaustive source reachability inventory.

The first execution from `C:/Temp` incorrectly failed all checks because the script derives its application root relative to its own location. Relocating the verifier into `catalog/bin` corrected the fixture setup; no production code was changed. The 16 workflow records remain mapped, not accepted. Full UE5.8.3 certification stays open.
