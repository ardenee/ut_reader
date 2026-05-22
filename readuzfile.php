<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', (string)(3600 * 5));
ini_set('memory_limit', '512M');

/*
UZ file format used here:
  1) DWORD signature: 1234 for .uz, 5678 for .uz3
  2) compact-ish filename length byte used by the old script
  3) original filename, null-terminated
  4) compressed file data

This script only reads/renames the wrapper files. It does not decompress payload data.
*/

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function signatureHex(string $data): string {
    $b = unpack('C4sig', $data);
    if (!is_array($b)) {
        return '0x00000000';
    }
    return '0x' . strtoupper(
        str_pad(dechex((int)$b['sig4']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$b['sig3']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$b['sig2']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$b['sig1']), 2, '0', STR_PAD_LEFT)
    );
}

function safeTargetPath(string $dir, string $originalName, string $uzExt): string {
    $originalName = str_replace(["\0", '/', '\\'], '_', trim($originalName));
    if ($originalName === '') {
        $originalName = 'unknown';
    }

    $target = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $originalName . $uzExt;
    if (!file_exists($target)) {
        return $target;
    }

    $path = dirname($target);
    $fnameNoExt = pathinfo($target, PATHINFO_FILENAME);
    $ext = pathinfo($target, PATHINFO_EXTENSION);
    $suffix = $ext !== '' ? '.' . $ext : '';

    $b = 1;
    do {
        $candidate = $path . DIRECTORY_SEPARATOR . $fnameNoExt . ' (' . $b . ')' . $suffix;
        $b++;
    } while (file_exists($candidate));

    return $candidate;
}

function readUzHeader(string $fileFull): array {
    $handle = fopen($fileFull, 'rb');
    if (!$handle) {
        throw new RuntimeException('Could not open file.');
    }

    try {
        $sigData = fread($handle, 4);
        if ($sigData === false || strlen($sigData) < 4) {
            throw new RuntimeException('File too small for UZ signature.');
        }

        $sigHex = signatureHex($sigData);
        $sigValue = hexdec($sigHex);
        if ($sigValue === 1234) {
            $uzExt = '.uz';
            $type = 'UZ';
        } elseif ($sigValue === 5678) {
            $uzExt = '.uz3';
            $type = 'UT3 UZ';
        } else {
            throw new RuntimeException('Incorrect UZ signature: ' . $sigHex);
        }

        $lenByte = fread($handle, 1);
        if ($lenByte === false || strlen($lenByte) < 1) {
            throw new RuntimeException('Missing original filename length byte.');
        }

        $nameSize = ord($lenByte);
        if ($nameSize <= 0 || $nameSize > 255) {
            throw new RuntimeException('Invalid original filename length: ' . $nameSize);
        }

        $nameData = fread($handle, $nameSize);
        if ($nameData === false || strlen($nameData) < $nameSize) {
            throw new RuntimeException('Could not read original filename.');
        }

        $originalName = rtrim($nameData, "\0\r\n\t ");

        return [
            'signature_hex' => $sigHex,
            'signature_value' => $sigValue,
            'type' => $type,
            'uz_ext' => $uzExt,
            'name_size' => $nameSize,
            'original_name' => $originalName,
        ];
    } finally {
        fclose($handle);
    }
}

$defaultScanDir = __DIR__ . '/uploads';
$scanDir = isset($_REQUEST['scan_dir']) && trim((string)$_REQUEST['scan_dir']) !== '' ? trim((string)$_REQUEST['scan_dir']) : $defaultScanDir;
$doScan = isset($_REQUEST['run']) && (string)$_REQUEST['run'] === '1';
$renameFiles = isset($_REQUEST['rename']) && (string)$_REQUEST['rename'] === '1';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UZ File Reader</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;background:#111;color:#ddd}input{padding:6px 8px;margin:4px;background:#1b1b1b;color:#ddd;border:1px solid #444;border-radius:4px}button,input[type=submit]{cursor:pointer;background:#26394f}code,pre{background:#000;color:#8f8;padding:8px;border-radius:6px}.box{border:1px solid #333;border-radius:8px;padding:12px;margin:12px 0;background:#181818}.err{color:#ff9f9f}.ok{color:#9fdf9f}.warn{color:#ffd27f}.muted{color:#999}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border-bottom:1px solid #333;padding:6px;text-align:left;vertical-align:top}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}</style>
</head>
<body>
<h1>UZ File Reader</h1>

<div class="box">
<form method="get">
    <input type="hidden" name="run" value="1">
    <label>Scan folder:<br><input type="text" name="scan_dir" value="<?=h($scanDir)?>" style="width:520px"></label><br>
    <label><input type="checkbox" name="rename" value="1" <?= $renameFiles ? 'checked' : '' ?>> Rename files to original filename + .uz/.uz3</label><br>
    <input type="submit" value="Scan UZ files">
</form>
<p class="muted">Default folder is <code><?=h($defaultScanDir)?></code>. Use a Synology/Linux path, not <code>M:\Epic\Games\uz</code>.</p>
</div>

<?php if ($doScan): ?>
<div class="box">
<?php
try {
    if (!is_dir($scanDir)) {
        throw new RuntimeException('Scan folder does not exist: ' . $scanDir);
    }
    if (!is_readable($scanDir)) {
        throw new RuntimeException('Scan folder is not readable by PHP/Web Station: ' . $scanDir);
    }

    $items = scandir($scanDir);
    if ($items === false) {
        throw new RuntimeException('Unable to scan folder: ' . $scanDir);
    }

    $files = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = rtrim($scanDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item;
        if (is_file($full)) {
            $files[] = $full;
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    echo '<p class="ok">Files found: ' . count($files) . '</p>';
    echo '<table><thead><tr><th>#</th><th>Current file</th><th>Signature</th><th>Original name</th><th>Target</th><th>Status</th></tr></thead><tbody>';

    $ok = 0;
    $bad = 0;
    foreach ($files as $i => $fileFull) {
        $status = '';
        $target = '';
        $sig = '';
        $orig = '';
        try {
            $info = readUzHeader($fileFull);
            $sig = $info['signature_hex'];
            $orig = $info['original_name'];
            $target = safeTargetPath(dirname($fileFull), $orig, $info['uz_ext']);

            if ($renameFiles) {
                if ($fileFull !== $target) {
                    if (!is_writable(dirname($fileFull))) {
                        throw new RuntimeException('Folder is not writable, cannot rename.');
                    }
                    if (!rename($fileFull, $target)) {
                        throw new RuntimeException('Rename failed.');
                    }
                    $status = 'Renamed';
                } else {
                    $status = 'Already correct';
                }
            } else {
                $status = 'OK - preview only';
            }
            $ok++;
            $statusClass = 'ok';
        } catch (Throwable $t) {
            $bad++;
            $target = $renameFiles ? safeTargetPath(dirname($fileFull), basename($fileFull) . '.bad', '') : '';
            $status = $t->getMessage();
            $statusClass = 'err';
        }

        echo '<tr>';
        echo '<td class="mono">' . ($i + 1) . '</td>';
        echo '<td class="mono">' . h(basename($fileFull)) . '</td>';
        echo '<td class="mono">' . h($sig) . '</td>';
        echo '<td>' . h($orig) . '</td>';
        echo '<td class="mono">' . h(basename($target)) . '</td>';
        echo '<td class="' . $statusClass . '">' . h($status) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p class="ok">Done. OK: ' . $ok . ', failed: ' . $bad . '</p>';
} catch (Throwable $t) {
    echo '<p class="err"><strong>Error:</strong> ' . h($t->getMessage()) . '</p>';
}
?>
</div>
<?php endif; ?>
</body>
</html>
