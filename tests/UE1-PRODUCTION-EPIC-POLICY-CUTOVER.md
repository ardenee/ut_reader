# UE1 Epic-source dependency policy production cutover

Status (2026-11-02): **NOT APPLIED**. The six UE1 reader/publisher/website implementation tests are PASS, but PRD dependency publication is still on the historical policy for 85,091 files (UT99 74,303; Unreal Gold 10,788). Source-correct UE1 dependency policy **uedb5-dependency-pass-ue1-mount-v14** is implemented. UE2–UE5 now have independent source-correct **v14** dependency policy markers; their existing v13 PRD projections also require separately controlled Pass 2 regeneration. The UE1 migration must not modify those engines.

## Execute locally on PRD Lelly-pc

Run in PowerShell locally on Lelly-pc:

    powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Apache24\htdocs\unrealdb\catalog\bin\apply-ue1-epic-policy-cutover.ps1"

This is read-only preflight, not the migration.

For the source-policy cutover, including per-file original backups and canary checks followed by full resumable Pass 2:

    powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Apache24\htdocs\unrealdb\catalog\bin\apply-ue1-epic-policy-cutover.ps1" -Apply

Optional: add -CanaryOnly to apply and verify just the two original-file canaries; then run without -CanaryOnly to complete. The default uses two Pass 2 workers, 100-file batches; it stops on worker failure and logs to C:\Temp\UnrealDB\UE1\epic-policy-cutover. It excludes other engines by game ID. Re-running skips already-current files based on exact policy and payload hash. Do not use force on the full bulk run; the script applies it only to backed-up canaries.

## Post-cutover verification

    C:\php8.5\php.exe "C:\Apache24\htdocs\unrealdb\catalog\bin\verify-ue1-epic-policy-cutover.php"

The verifier succeeds only if all verified UE1 files have v14 policy and matching registered UEDB5 payload hashes, zero UE1 v13 status rows, and the UE1-only policy does not appear under UE2–UE4. Those engines can independently remain stale until their own Pass 2 cutovers.

## Safety and access boundary

- DEV (game-amd) has read-only PRD MySQL privileges. A DEV-side canary wrote a UEDB5 file before its SQL registration was rejected. Original PRD file 113256 was immediately restored from exact backup SHA-256; read-only database verification confirmed v13, eight original edges and three package summaries. No dependency registration was changed.
- Desktop Commander currently lists DEV but not PRD; SSH, WinRM and remote scheduled-task execution on PRD were unavailable. No database grant bypass or production command-executing web endpoint will be introduced.
- The PRD-local cutover uses PRD's ordinary MySQL privileges and the existing UEDB5 publication service. It does not mass-UPDATE policy labels on stale edges, which would falsely authenticate old outcomes.
- Original source defines the permitted table-only import projection; unavailable original mount order stays unresolved rather than incorrectly missing/resolved. No mesh, texture, sound or UObject payload loading.
- Until the PRD-local cutover finishes, the website correctly suppresses old UE1 dependency rows. Six passing verification contracts do NOT mean the 85,091-file production migration has completed.

Deploying the script via DEV/GitHub/PRD does not execute it. Rollout status remains NOT APPLIED until the completion verifier succeeds.
