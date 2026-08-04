#!/usr/bin/env python3
import base64
import hashlib
import zlib
from pathlib import Path

root = Path(__file__).resolve().parent
parts = [root / f'cleanup.part{index}' for index in range(1, 7)]
encoded = ''.join(part.read_text(encoding='ascii').strip() for part in parts)
if hashlib.sha256(encoded.encode('ascii')).hexdigest() != '6038e0a191afe50bcffc6b5cc0e9f91ca680727fadab5df79537d06a61611783':
    raise RuntimeError('Cleanup payload checksum mismatch.')
payload = zlib.decompress(base64.b64decode(encoded, validate=True))
if hashlib.sha256(payload).hexdigest() != 'fff81951ecb02159d83b7b16d5e6194f19314c7e14f08df1f18ba8cf1d8a383d':
    raise RuntimeError('Cleanup source checksum mismatch.')
exec(compile(payload, str(Path(__file__)), 'exec'))
for part in parts:
    part.unlink(missing_ok=True)
(root / 'complete-db-cleanup.py').unlink(missing_ok=True)
Path(__file__).unlink(missing_ok=True)
