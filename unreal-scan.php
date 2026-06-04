<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', (string)(3600 * 5));
ini_set('memory_limit', '512M');

$dbhost = 'localhost';
$dbname = 'unreal_files';
$dbuser = 'root';
$dbpass = 'MyPASSWORD';

$defaultScanDir = __DIR__ . '/uploads';
$scanDir = isset($_REQUEST['scan_dir']) && trim((string)$_REQUEST['scan_dir']) !== '' ? trim((string)$_REQUEST['scan_dir']) : $defaultScanDir;
$doScan = isset($_REQUEST['run']) && (string)$_REQUEST['run'] === '1';
$messages = [];
$errors = [];
$inserted = 0;
$skipped = 0;
$failed = 0;

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function out_msg(array &$messages, string $message): void {
    $messages[] = $message;
    if (PHP_SAPI !== 'cli') {
        echo h($message) . "<br>\n";
        @ob_flush();
        @flush();
    }
}

function out_err(array &$errors, string $message): void {
    $errors[] = $message;
    if (PHP_SAPI !== 'cli') {
        echo '<span style="color:#b00020">' . h($message) . "</span><br>\n";
        @ob_flush();
        @flush();
    }
}

function tableExists(PDO $db): bool {
    $stmt = $db->query("SHOW TABLES LIKE 'files'");
    return (bool)$stmt->fetchColumn();
}

function ensureFilesTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS files (
        id int NOT NULL AUTO_INCREMENT,
        FileName varchar(150) NOT NULL DEFAULT 'Unknown',
        FilePath varchar(200) DEFAULT NULL,
        FileSize bigint NOT NULL DEFAULT 0,
        FileHash varchar(40) NOT NULL DEFAULT 'Unknown',
        FileType varchar(10) NOT NULL DEFAULT '',
        FileVersion int NOT NULL DEFAULT 0,
        FileGUID varchar(36) NOT NULL DEFAULT 'Unknown',
        PRIMARY KEY (id),
        KEY idx_filehash (FileHash),
        KEY idx_filename (FileName)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getFileList(string $dir): array {
    if (!is_dir($dir)) {
        throw new RuntimeException('Scan folder does not exist: ' . $dir);
    }
    if (!is_readable($dir)) {
        throw new RuntimeException('Scan folder is not readable by PHP/Web Station: ' . $dir);
    }

    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException('Unable to scan folder: ' . $dir);
    }

    $files = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item;
        if (!is_file($path)) {
            continue;
        }
        $files[] = $path;
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function getFileHeaderData(string $filename): array {
    $header = [
        'Header' => 'Unknown',
        'Version' => 0,
        'GUID' => 'Unknown',
    ];

    if (!is_readable($filename)) {
        throw new RuntimeException('File is not readable: ' . $filename);
    }

    $handle = fopen($filename, 'rb');
    if (!$handle) {
        throw new RuntimeException('Could not open file: ' . $filename);
    }

    try {
        $data = fread($handle, 52);
        if ($data === false || strlen($data) < 52) {
            throw new RuntimeException('File is too small to contain an Unreal package header: ' . $filename);
        }

        $parsed = unpack('C4UnrealHeader/vVersion/vLicenseMode/v2PackageFlags/VNumberOfNames/VNameDirectoryOffset/VNumberOfFiles/VFileDirectoryOffset/VNumberOfTypes/VTypeDirectoryOffset/C16GUIDHash', $data);
        if (!is_array($parsed)) {
            throw new RuntimeException('Unable to unpack header: ' . $filename);
        }

        $parsed['Header'] = getFileHeader($parsed);
        $parsed['GUID'] = getGUID($parsed);
        return $parsed;
    } finally {
        fclose($handle);
    }
}

function getFileHeader(array $header): string {
    return '0x' . strtoupper(
        str_pad(dechex((int)$header['UnrealHeader4']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['UnrealHeader3']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['UnrealHeader2']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['UnrealHeader1']), 2, '0', STR_PAD_LEFT)
    );
}

function getGUID(array $header): string {
    return strtoupper(
        str_pad(dechex((int)$header['GUIDHash4']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash3']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash2']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash1']), 2, '0', STR_PAD_LEFT) . '-' .
        str_pad(dechex((int)$header['GUIDHash6']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash5']), 2, '0', STR_PAD_LEFT) . '-' .
        str_pad(dechex((int)$header['GUIDHash8']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash7']), 2, '0', STR_PAD_LEFT) . '-' .
        str_pad(dechex((int)$header['GUIDHash9']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash10']), 2, '0', STR_PAD_LEFT) . '-' .
        str_pad(dechex((int)$header['GUIDHash11']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash12']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash13']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash14']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash15']), 2, '0', STR_PAD_LEFT) .
        str_pad(dechex((int)$header['GUIDHash16']), 2, '0', STR_PAD_LEFT)
    );
}

function extensionForPath(string $path): string {
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '') {
        return 'unknown';
    }
    if (strlen($ext) > 10) {
        return substr($ext, 0, 10);
    }
    return $ext;
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Unreal File Scanner</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;background:#111;color:#ddd}input{padding:6px 8px;margin:4px;background:#1b1b1b;color:#ddd;border:1px solid #444;border-radius:4px}button,input[type=submit]{cursor:pointer;background:#26394f}code,pre{background:#000;color:#8f8;padding:8px;border-radius:6px}label{display:block;margin:8px 0}.box{border:1px solid #333;border-radius:8px;padding:12px;margin:12px 0;background:#181818}.err{color:#ff9f9f}.ok{color:#9fdf9f}.muted{color:#999}</style>
</head>
<body>
<h1>Unreal File Scanner</h1>

<div class="box">
<form method="get">
    <input type="hidden" name="run" value="1">
    <label>Scan folder:<br><input type="text" name="scan_dir" value="<?=h($scanDir)?>" style="width:520px"></label>
    <input type="submit" value="Scan folder">
</form>
<p class="muted">Default folder is <code><?=h($defaultScanDir)?></code>. Use a Synology/Linux path, not <code>O:\un-uz2</code>.</p>
</div>

<?php
if ($doScan) {
    echo '<div class="box"><strong>Scan started...</strong><br>';

    try {
        $dsn = 'mysql:host=' . $dbhost . ';dbname=' . $dbname . ';charset=utf8mb4';
        $dblink = new PDO($dsn, $dbuser, $dbpass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        ensureFilesTable($dblink);
        out_msg($messages, 'Connected to database: ' . $dbname);

        $files = getFileList($scanDir);
        $filesCount = count($files);
        out_msg($messages, 'Files found: ' . $filesCount);

        $stmt = $dblink->prepare("INSERT INTO files(FileName, FilePath, FileSize, FileHash, FileType, FileVersion, FileGUID)
            VALUES(:FileName, :FilePath, :FileSize, :FileHash, :FileType, :FileVersion, :FileGUID)");

        foreach ($files as $index => $fileFull) {
            try {
                $fileSize = filesize($fileFull);
                if ($fileSize === false) {
                    throw new RuntimeException('Unable to read file size');
                }

                $fileHash = sha1_file($fileFull);
                if ($fileHash === false) {
                    throw new RuntimeException('Unable to calculate SHA1');
                }
                $fileHash = strtoupper($fileHash);

                $fileHeader = getFileHeaderData($fileFull);
                $fileName = basename($fileFull);
                $filePath = dirname($fileFull);
                $fileType = extensionForPath($fileFull);
                $fileVersion = (int)($fileHeader['Version'] ?? 0);
                $fileGUID = (string)($fileHeader['GUID'] ?? 'Unknown');

                $stmt->execute([
                    ':FileName' => $fileName,
                    ':FilePath' => $filePath,
                    ':FileSize' => (int)$fileSize,
                    ':FileHash' => $fileHash,
                    ':FileType' => $fileType,
                    ':FileVersion' => $fileVersion,
                    ':FileGUID' => $fileGUID,
                ]);

                $inserted++;
                out_msg($messages, ($index + 1) . '/' . $filesCount . ' - Added: ' . $fileName . ' - ' . $fileHash);
            } catch (Throwable $t) {
                $failed++;
                out_err($errors, ($index + 1) . '/' . $filesCount . ' - Failed: ' . basename($fileFull) . ' - ' . $t->getMessage());
                continue;
            }
        }

        out_msg($messages, 'Done. Added: ' . $inserted . ', failed/skipped: ' . $failed);
    } catch (Throwable $t) {
        out_err($errors, 'Fatal scan error: ' . $t->getMessage());
    }

    echo '</div>';
}
?>
</body>
</html>
