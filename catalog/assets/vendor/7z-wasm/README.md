# 7z-wasm browser decoder

Pinned upstream commit: `521d2cf93f5964f4e77b01049e19f1b29305c454` (7z-wasm 1.2.0 / 7-Zip 24.09).

UnrealDB uses the single-threaded WORKERFS build for browser-side ZIP/RAR/7z
source inspection. The source archive is mounted without a whole-file copy.
Only one Unreal member is extracted at a time and its worker is terminated
after skip/upload.

Install the generated WASM binary locally with:

```powershell
php catalog/bin/install-browser-archive-decoder.php
```

The installer verifies byte count and the pinned Git blob SHA-1. The binary is
ignored by git. RAR support is subject to the included unRAR restriction.
