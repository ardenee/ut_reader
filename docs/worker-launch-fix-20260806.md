# Windows detached worker launch fix

The Windows web launcher previously used `cmd.exe`'s `start /B` through `popen()`. On the production host that command returned without creating the requested PHP worker processes, leaving each slot in a stale `launching` state and causing the page to append unrelated historical log output.

The launcher now uses Windows PowerShell `Start-Process` with the configured PHP CLI path, a hidden process, explicit working directory, and separate per-slot stdout/stderr files. Existing slot logs are cleared before each launch so startup failures report only the current attempt. Startup verification waits up to ten seconds for a real lock or terminal worker state.
