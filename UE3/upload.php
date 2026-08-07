<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Accepts local package uploads for the standalone `UE3` reader/viewer.
 * Why: It lets parser developers open sample packages without using the main catalog upload pipeline.
 * Role: Legacy/reference upload helper outside the supported catalog application.
 * Audit: Legacy/reference area; verify active parser callers before deleting or folding it into shared reader code.
 */
declare(strict_types=1);

$uploadDir = __DIR__ . '/uploads';
$allowed = ['u', 'utx', 'umx', 'uax', 'unr', 'ut2', 'ut3', 'upk'];
$message = '';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function safe_package_name(string $name): string
{
    $base = basename(str_replace('\\', '/', $name));
    $base = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base) ?? '';
    $base = trim($base, " .\t\n\r\0\x0B");
    return $base !== '' ? $base : ('package_' . date('Ymd_His'));
}

function upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'The file is larger than upload_max_filesize in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'The file is larger than MAX_FILE_SIZE in the form.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary upload folder configured.',
        UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        default => 'Unknown PHP upload error.',
    };
}

function ensure_upload_dir(string $uploadDir, string &$message): bool
{
    clearstatcache(true, $uploadDir);
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            $message = 'Could not create uploads folder: ' . $uploadDir;
            return false;
        }
    }
    @chmod($uploadDir, 0775);
    clearstatcache(true, $uploadDir);
    if (!is_writable($uploadDir)) {
        $message = 'Uploads folder exists but PHP cannot write to it: ' . $uploadDir . "\n\n" .
            'Fix on Synology/host shell, for example:' . "\n" .
            'chmod 775 ' . escapeshellarg($uploadDir) . "\n" .
            'chown http:http ' . escapeshellarg($uploadDir) . '   # adjust user/group if your web user differs';
        return false;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ensure_upload_dir($uploadDir, $message)) {
        // message set by helper
    } elseif (!isset($_FILES['package_file']) || !is_array($_FILES['package_file'])) {
        $message = 'No file was selected.';
    } else {
        $err = (int)($_FILES['package_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $message = 'Upload failed. ' . upload_error_text($err) . ' PHP upload error code: ' . $err;
        } else {
            $tmp = (string)($_FILES['package_file']['tmp_name'] ?? '');
            $name = safe_package_name((string)($_FILES['package_file']['name'] ?? ''));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $message = 'Unsupported file type: .' . $ext;
            } elseif ($tmp === '' || !is_uploaded_file($tmp)) {
                $message = 'PHP did not provide a valid uploaded temp file.';
            } else {
                $target = $uploadDir . DIRECTORY_SEPARATOR . $name;
                clearstatcache(true, $uploadDir);
                if (move_uploaded_file($tmp, $target)) {
                    @chmod($target, 0664);
                    header('Location: UE3.php?file=uploads/' . rawurlencode($name));
                    exit;
                }
                $last = error_get_last();
                $message = 'Failed to move uploaded file into uploads folder.' . "\n\n" .
                    'Target: ' . $target . "\n" .
                    'Uploads writable: ' . (is_writable($uploadDir) ? 'yes' : 'no') . "\n" .
                    'PHP temp file: ' . $tmp . "\n" .
                    'Last PHP error: ' . (($last['message'] ?? '') !== '' ? $last['message'] : '');
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Upload UE3 package</title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#eef6f8;color:#071629;margin:0;padding:20px}.box{background:#fff;border:1px solid #cfd7df;max-width:820px;padding:18px}input,button{font:inherit}button{padding:6px 12px}.msg{white-space:pre-wrap;border:1px solid #d1242f;background:#fff8f8;padding:8px;margin-bottom:12px}.hint{color:#536471;margin-top:12px}code{background:#f6f8fa;padding:1px 4px}
</style>
</head>
<body>
<div class="box">
<h1>Upload UE3 package</h1>
<?php if ($message !== ''): ?><div class="msg"><?= h($message) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="package_file" accept=".u,.utx,.umx,.uax,.unr,.ut2,.ut3,.upk" required>
    <button type="submit">Upload and open</button>
</form>
<p class="hint">Files are saved to <code>UE3/uploads/</code> and then opened in <code>UE3.php</code>.</p>
<p><a href="UE3.php">Back to UE3 viewer</a></p>
</div>
</body>
</html>
