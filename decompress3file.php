<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', (string)(3600 * 5));
ini_set('memory_limit', '1024M');

/*
UT3 .uz3 format handled here:
  1) uint32 little-endian signature: 5678 / 0x0000162E
  2) uint32 little-endian uncompressed file size
  3) zlib-compressed payload
*/

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function read_exact($handle, int $length): string {
    if ($length < 0) {
        throw new InvalidArgumentException('Negative read length.');
    }
    if ($length === 0) {
        return '';
    }
    $data = fread($handle, $length);
    if ($data === false || strlen($data) !== $length) {
        throw new RuntimeException('Unexpected end of file. Wanted ' . $length . ' bytes, got ' . ($data === false ? 0 : strlen($data)) . '.');
    }
    return $data;
}

function read_u32le($handle): int {
    $v = unpack('V', read_exact($handle, 4));
    return (int)$v[1];
}

function safe_output_name(string $inputPath, string $outputDir): string {
    $base = basename($inputPath);
    $base = preg_replace('/\.uz3$/i', '', $base);
    $base = preg_replace('/[^A-Za-z0-9_. -]/', '_', $base);
    if ($base === '' || $base === null) {
        $base = 'decompressed.ut3';
    }

    $target = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base;
    if (!file_exists($target)) {
        return $target;
    }

    $dir = dirname($target);
    $name = pathinfo($target, PATHINFO_FILENAME);
    $ext = pathinfo($target, PATHINFO_EXTENSION);
    $suffix = $ext !== '' ? '.' . $ext : '';
    $i = 1;
    do {
        $candidate = $dir . DIRECTORY_SEPARATOR . $name . ' (' . $i . ')' . $suffix;
        $i++;
    } while (file_exists($candidate));

    return $candidate;
}

function decompress_uz3_file(string $inputPath, string $outputDir): array {
    if (!is_file($inputPath)) {
        throw new RuntimeException('Input file not found: ' . $inputPath);
    }
    if (!is_readable($inputPath)) {
        throw new RuntimeException('Input file is not readable by PHP/Web Station: ' . $inputPath);
    }
    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true)) {
        throw new RuntimeException('Output folder does not exist and could not be created: ' . $outputDir);
    }
    if (!is_writable($outputDir)) {
        throw new RuntimeException('Output folder is not writable by PHP/Web Station: ' . $outputDir);
    }

    $fileSize = filesize($inputPath);
    if ($fileSize === false || $fileSize < 8) {
        throw new RuntimeException('Input file is too small to be a UZ3 file.');
    }

    $handle = fopen($inputPath, 'rb');
    if (!$handle) {
        throw new RuntimeException('Could not open input file: ' . $inputPath);
    }

    try {
        $signature = read_u32le($handle);
        if ($signature !== 5678) {
            throw new RuntimeException(sprintf('Incorrect UZ3 signature: 0x%08X', $signature));
        }

        $expectedSize = read_u32le($handle);
        if ($expectedSize <= 0) {
            throw new RuntimeException('Invalid uncompressed file size: ' . $expectedSize);
        }

        $compressedLen = $fileSize - 8;
        $compressed = read_exact($handle, $compressedLen);
        $decoded = zlib_decode($compressed, $expectedSize > 0 ? $expectedSize : 0);
        if ($decoded === false) {
            throw new RuntimeException('zlib_decode() failed. The payload may not be plain zlib data or the file is corrupt.');
        }

        $actualSize = strlen($decoded);
        if ($actualSize !== $expectedSize) {
            throw new RuntimeException('Decoded size mismatch. Expected ' . $expectedSize . ' bytes, got ' . $actualSize . ' bytes.');
        }

        $target = safe_output_name($inputPath, $outputDir);
        if (file_put_contents($target, $decoded) === false) {
            throw new RuntimeException('Could not write output file: ' . $target);
        }

        return [
            'input' => $inputPath,
            'output' => $target,
            'signature' => sprintf('0x%08X', $signature),
            'compressed_size' => $compressedLen,
            'uncompressed_size' => $actualSize,
        ];
    } finally {
        fclose($handle);
    }
}

$defaultInputDir = __DIR__ . '/uploads';
$defaultOutputDir = __DIR__ . '/decompressed';
$inputDir = isset($_REQUEST['input_dir']) && trim((string)$_REQUEST['input_dir']) !== '' ? trim((string)$_REQUEST['input_dir']) : $defaultInputDir;
$outputDir = isset($_REQUEST['output_dir']) && trim((string)$_REQUEST['output_dir']) !== '' ? trim((string)$_REQUEST['output_dir']) : $defaultOutputDir;
$doRun = isset($_REQUEST['run']) && (string)$_REQUEST['run'] === '1';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UZ3 Decompressor</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;background:#111;color:#ddd}input{padding:6px 8px;margin:4px;background:#1b1b1b;color:#ddd;border:1px solid #444;border-radius:4px}input[type=submit]{cursor:pointer;background:#26394f}code{background:#000;color:#8f8;padding:2px 4px;border-radius:4px}.box{border:1px solid #333;border-radius:8px;padding:12px;margin:12px 0;background:#181818}.err{color:#ff9f9f}.ok{color:#9fdf9f}.muted{color:#999}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border-bottom:1px solid #333;padding:6px;text-align:left;vertical-align:top}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}</style>
</head>
<body>
<h1>UZ3 Decompressor</h1>

<div class="box">
<form method="get">
    <input type="hidden" name="run" value="1">
    <label>Input folder:<br><input type="text" name="input_dir" value="<?=h($inputDir)?>" style="width:620px"></label><br>
    <label>Output folder:<br><input type="text" name="output_dir" value="<?=h($outputDir)?>" style="width:620px"></label><br>
    <input type="submit" value="Decompress .uz3 files">
</form>
<p class="muted">Default input folder is <code><?=h($defaultInputDir)?></code>. Use Synology/Linux paths, not <code>M:\Epic\Games\uz32</code>.</p>
</div>

<?php if ($doRun): ?>
<div class="box">
<?php
try {
    if (!is_dir($inputDir)) {
        throw new RuntimeException('Input folder does not exist: ' . $inputDir);
    }
    if (!is_readable($inputDir)) {
        throw new RuntimeException('Input folder is not readable by PHP/Web Station: ' . $inputDir);
    }

    $items = scandir($inputDir);
    if ($items === false) {
        throw new RuntimeException('Unable to scan input folder: ' . $inputDir);
    }

    $files = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = rtrim($inputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item;
        if (is_file($full) && preg_match('/\.uz3$/i', $item)) {
            $files[] = $full;
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    echo '<p class="ok">UZ3 files found: ' . count($files) . '</p>';
    echo '<table><thead><tr><th>#</th><th>Input</th><th>Signature</th><th>Compressed</th><th>Uncompressed</th><th>Output</th><th>Status</th></tr></thead><tbody>';

    $ok = 0;
    $bad = 0;
    foreach ($files as $i => $file) {
        $sig = '';
        $compressed = '';
        $uncompressed = '';
        $output = '';
        try {
            $result = decompress_uz3_file($file, $outputDir);
            $sig = $result['signature'];
            $compressed = number_format($result['compressed_size']);
            $uncompressed = number_format($result['uncompressed_size']);
            $output = basename($result['output']);
            $status = 'Decompressed';
            $class = 'ok';
            $ok++;
        } catch (Throwable $t) {
            $status = $t->getMessage();
            $class = 'err';
            $bad++;
        }

        echo '<tr>';
        echo '<td class="mono">' . ($i + 1) . '</td>';
        echo '<td class="mono">' . h(basename($file)) . '</td>';
        echo '<td class="mono">' . h($sig) . '</td>';
        echo '<td class="mono">' . h($compressed) . '</td>';
        echo '<td class="mono">' . h($uncompressed) . '</td>';
        echo '<td class="mono">' . h($output) . '</td>';
        echo '<td class="' . $class . '">' . h($status) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p class="ok">Done. Decompressed: ' . $ok . ', failed: ' . $bad . '</p>';
} catch (Throwable $t) {
    echo '<p class="err"><strong>Error:</strong> ' . h($t->getMessage()) . '</p>';
}
?>
</div>
<?php endif; ?>
</body>
</html>
