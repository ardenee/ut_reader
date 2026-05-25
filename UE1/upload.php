<?php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $message = 'Could not create uploads folder.';
    } elseif (!isset($_FILES['package_file']) || !is_array($_FILES['package_file'])) {
        $message = 'No file was selected.';
    } elseif ((int)($_FILES['package_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $message = 'Upload failed. PHP upload error code: ' . (int)$_FILES['package_file']['error'];
    } else {
        $name = safe_package_name((string)($_FILES['package_file']['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $message = 'Unsupported file type: .' . $ext;
        } else {
            $target = $uploadDir . DIRECTORY_SEPARATOR . $name;
            if (move_uploaded_file((string)$_FILES['package_file']['tmp_name'], $target)) {
                header('Location: UE1.php?file=uploads/' . rawurlencode($name));
                exit;
            }
            $message = 'Failed to move uploaded file into uploads folder.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Upload UE1 package</title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#eef6f8;color:#071629;margin:0;padding:20px}.box{background:#fff;border:1px solid #cfd7df;max-width:720px;padding:18px}input,button{font:inherit}button{padding:6px 12px}.msg{border:1px solid #d1242f;background:#fff8f8;padding:8px;margin-bottom:12px}.hint{color:#536471;margin-top:12px}
</style>
</head>
<body>
<div class="box">
<h1>Upload UE package</h1>
<?php if ($message !== ''): ?><div class="msg"><?= h($message) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="package_file" accept=".u,.utx,.umx,.uax,.unr,.ut2,.ut3,.upk" required>
    <button type="submit">Upload and open</button>
</form>
<p class="hint">Files are saved to <code>UE1/uploads/</code> and then opened in <code>UE1.php</code>.</p>
<p><a href="UE1.php">Back to UE1 viewer</a></p>
</div>
</body>
</html>
