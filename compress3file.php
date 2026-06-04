<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', (string)(3600 * 5));
ini_set('memory_limit', '1024M');

/*
UT3 .uz3 format written here:
  1) uint32 little-endian signature: 5678 / 0x0000162E
  2) uint32 little-endian uncompressed file size
  3) zlib-compressed payload
*/

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_output_name(string $inputPath, string $outputDir): string {
    $base = basename($inputPath);
    $base = preg_replace('/\.uz3$/i', '', $base);
    $base = preg_replace('/[^A-Za-z0-9_. -]/', '_', $base);
    if ($base === '' || $base === null) {
        $base = 'compressed';
    }

    $target = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base . '.uz3';
    if (!file_exists($target)) {
        return $target;
    }

    $dir = dirname($target);
    $name = pathinfo($target, PATHINFO_FILENAME);
    $i = 1;
    do {
        $candidate = $dir . DIRECTORY_SEPARATOR . $name . ' (' . $i . ').uz3';
        $i++;
    } while (file_exists($candidate));

    return $candidate;
}

function compress_to_uz3(string $inputPath, string $outputDir, int $level = 9): array {
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

    $raw = file_get_contents($inputPath);
    if ($raw === false) {
        throw new RuntimeException('Could not read input file: ' . $inputPath);
    }

    $uncompressedSize = strlen($raw);
    if ($uncompressedSize <= 0) {
        throw new RuntimeException('Input file is empty.');
    }
    if ($uncompressedSize > 0x7FFFFFFF) {
        throw new RuntimeException('Input file is too large for this UZ3 writer: ' . $uncompressedSize . ' bytes.');
    }

    $compressed = zlib_encode($raw, ZLIB_ENCODING_DEFLATE, $level);
    if ($compressed === false) {
        throw new RuntimeException('zlib_encode() failed.');
    }

    $payload = pack('V', 5678) . pack('V', $uncompressedSize) . $compressed;
    $target = safe_output_name($inputPath, $outputDir);

    if (file_put_contents($target, $payload) === false) {
        throw new RuntimeException('Could not write output file: ' . $target);
    }

    return [
        'input' => $inputPath,
        'output' => $target,
        'uncompressed_size' => $uncompressedSize,
        'compressed_size' => strlen($compressed),
        'total_size' => strlen($payload),
        'ratio' => $uncompressedSize > 0 ? (strlen($payload) / $uncompressedSize) : 0,
    ];
}

$defaultInputDir = __DIR__ . '/decompressed';
$defaultOutputDir = __DIR__ . '/uploads';
$inputDir = isset($_REQUEST['input_dir']) && trim((string)$_REQUEST['input_dir']) !== '' ? trim((string)$_REQUEST['input_dir']) : $defaultInputDir;
$outputDir = isset($_REQUEST['output_dir']) && trim((string)$_REQUEST['output_dir']) !== '' ? trim((string)$_REQUEST['output_dir']) : $defaultOutputDir;
$level = isset($_REQUEST['level']) ? (int)$_REQUEST['level'] : 9;
if ($level < 0 || $level > 9) {
    $level = 9;
}
$doRun = isset($_REQUEST['run']) && (string)$_REQUEST['run'] === '1';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UZ3 Compressor</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;background:#111;color:#ddd}input,select{padding:6px 8px;margin:4px;background:#1b1b1b;color:#ddd;border:1px solid #444;border-radius:4px}input[type=submit]{cursor:pointer;background:#26394f}code{background:#000;color:#8f8;padding:2px 4px;border-radius:4px}.box{border:1px solid #333;border-radius:8px;padding:12px;margin:12px 0;background:#181818}.err{color:#ff9f9f}.ok{color:#9fdf9f}.muted{color:#999}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border-bottom:1px solid #333;padding:6px;text-align:left;vertical-align:top}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}</style>
</head>
<body>
<h1>UZ3 Compressor</h1>

<div class="box">
<form method="get">
    <input type="hidden" name="run" value="1">
    <label>Input folder:<br><input type="text" name="input_dir" value="<?=h($inputDir)?>" style="width:620px"></label><br>
    <label>Output folder:<br><input type="text" name="output_dir" value="<?=h($outputDir)?>" style="width:620px"></label><br>
    <label>Compression level:
        <select name="level">
            <?php for ($i = 0; $i <= 9; $i++): ?>
                <option value="<?=$i?>" <?= $level === $i ? 'selected' : '' ?>><?=$i?></option>
            <?php endfor; ?>
        </select>
    </label><br>
    <input type="submit" value="Compress files to .uz3">
</form>
<p class="muted">Default input folder is <code><?=h($defaultInputDir)?></code>. Use Synology/Linux paths, not <code>M:\Epic\Games\uz32</code>.</p>
<p class="muted">Existing <code>.uz3</code> files are skipped to avoid recompressing compressed files.</p>
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
        if (is_file($full) && !preg_match('/\.uz3$/i', $item)) {
            $files[] = $full;
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    echo '<p class="ok">Files found: ' . count($files) . '</p>';
    echo '<table><thead><tr><th>#</th><th>Input</th><th>Original size</th><th>Compressed payload</th><th>Total .uz3 size</th><th>Ratio</th><th>Output</th><th>Status</th></tr></thead><tbody>';

    $ok = 0;
    $bad = 0;
    foreach ($files as $i => $file) {
        $original = '';
        $compressed = '';
        $total = '';
        $ratio = '';
        $output = '';
        try {
            $result = compress_to_uz3($file, $outputDir, $level);
            $original = number_format($result['uncompressed_size']);
            $compressed = number_format($result['compressed_size']);
            $total = number_format($result['total_size']);
            $ratio = number_format($result['ratio'] * 100, 2) . '%';
            $output = basename($result['output']);
            $status = 'Compressed';
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
        echo '<td class="mono">' . h($original) . '</td>';
        echo '<td class="mono">' . h($compressed) . '</td>';
        echo '<td class="mono">' . h($total) . '</td>';
        echo '<td class="mono">' . h($ratio) . '</td>';
        echo '<td class="mono">' . h($output) . '</td>';
        echo '<td class="' . $class . '">' . h($status) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p class="ok">Done. Compressed: ' . $ok . ', failed: ' . $bad . '</p>';
} catch (Throwable $t) {
    echo '<p class="err"><strong>Error:</strong> ' . h($t->getMessage()) . '</p>';
}
?>
</div>
<?php endif; ?>
</body>
</html>
