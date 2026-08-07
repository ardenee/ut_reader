<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the shared directory index and local upload pages used by the standalone UE1-UE5 parser tools.
 * Why: The engine folders previously carried near-identical copies of upload validation, file naming, HTML, and
 *      directory-listing code even though only the engine label and allowed extensions differed.
 * Role: Shared presentation/upload helper for legacy standalone parser tooling outside the main `/catalog/` app.
 * Audit: Keep engine-specific parsing in the UE1-UE5 folders; only generic standalone page behavior belongs here.
 */
declare(strict_types=1);

namespace UtReader\Standalone;

final class StandalonePackageUi
{
    /** @param list<string> $allowedExtensions */
    public static function renderUploadPage(string $engine, string $baseDir, array $allowedExtensions): void
    {
        $engine = self::cleanEngine($engine);
        $uploadDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'uploads';
        $allowed = array_values(array_unique(array_map(
            static fn(string $extension): string => strtolower(ltrim(trim($extension), '.')),
            $allowedExtensions
        )));
        $message = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $message = self::handleUpload($engine, $uploadDir, $allowed);
        }

        $accept = implode(',', array_map(static fn(string $extension): string => '.' . $extension, $allowed));
        $viewer = $engine . '.php';
        $title = 'Upload ' . $engine . ' package';
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= self::h($title) ?></title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#eef6f8;color:#071629;margin:0;padding:20px}.box{background:#fff;border:1px solid #cfd7df;max-width:820px;padding:18px}input,button{font:inherit}button{padding:6px 12px}.msg{white-space:pre-wrap;border:1px solid #d1242f;background:#fff8f8;padding:8px;margin-bottom:12px}.hint{color:#536471;margin-top:12px}code{background:#f6f8fa;padding:1px 4px}
</style>
</head>
<body>
<div class="box">
<h1><?= self::h($title) ?></h1>
<?php if ($message !== ''): ?><div class="msg"><?= self::h($message) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="package_file" accept="<?= self::h($accept) ?>" required>
    <button type="submit">Upload and open</button>
</form>
<p class="hint">Files are saved to <code><?= self::h($engine) ?>/uploads/</code> and then opened in <code><?= self::h($viewer) ?></code>.</p>
<p><a href="<?= self::h($viewer) ?>">Back to <?= self::h($engine) ?> viewer</a></p>
</div>
</body>
</html>
<?php
    }

    public static function renderDirectoryIndex(string $baseDir, string $title = 'UT Reader Files'): void
    {
        $files = scandir($baseDir);
        if ($files === false) {
            $files = [];
        }

        echo "<!doctype html><html><head><meta charset='utf-8'><title>" . self::h($title) . "</title>";
        echo "<style>\nbody{font-family:system-ui;margin:24px;background:#111;color:#ddd}\na{color:#8cf;text-decoration:none}\ntable{border-collapse:collapse;width:100%}\ntd,th{border-bottom:1px solid #333;padding:6px;text-align:left}\n.mono{font-family:monospace}\n</style>";
        echo '</head><body><h1>' . self::h($title) . '</h1>';
        echo "<table><thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th></tr></thead><tbody>";

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') {
                continue;
            }

            $path = $baseDir . DIRECTORY_SEPARATOR . $file;
            $url = rawurlencode($file);
            $size = is_file($path) ? number_format((int)filesize($path)) : '-';
            $modified = @filemtime($path);

            echo '<tr>';
            echo "<td><a href='{$url}'>" . self::h($file) . '</a></td>';
            echo "<td class='mono'>" . (is_dir($path) ? 'folder' : 'file') . '</td>';
            echo "<td class='mono'>" . self::h($size) . '</td>';
            echo "<td class='mono'>" . ($modified !== false ? date('Y-m-d H:i:s', $modified) : '-') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></body></html>';
    }

    /** @param list<string> $allowed */
    private static function handleUpload(string $engine, string $uploadDir, array $allowed): string
    {
        $directoryError = self::ensureUploadDir($uploadDir);
        if ($directoryError !== '') {
            return $directoryError;
        }

        if (!isset($_FILES['package_file']) || !is_array($_FILES['package_file'])) {
            return 'No file was selected.';
        }

        $file = $_FILES['package_file'];
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return 'Upload failed. ' . self::uploadErrorText($error) . ' PHP upload error code: ' . $error;
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $name = self::safePackageName((string)($file['name'] ?? ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            return 'Unsupported file type: .' . $extension;
        }
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'PHP did not provide a valid uploaded temp file.';
        }

        $target = $uploadDir . DIRECTORY_SEPARATOR . $name;
        clearstatcache(true, $uploadDir);
        if (move_uploaded_file($tmp, $target)) {
            @chmod($target, 0664);
            header('Location: ' . $engine . '.php?file=uploads/' . rawurlencode($name));
            exit;
        }

        $last = error_get_last();
        return 'Failed to move uploaded file into uploads folder.' . "\n\n"
            . 'Target: ' . $target . "\n"
            . 'Uploads writable: ' . (is_writable($uploadDir) ? 'yes' : 'no') . "\n"
            . 'PHP temp file: ' . $tmp . "\n"
            . 'Last PHP error: ' . (($last['message'] ?? '') !== '' ? $last['message'] : '');
    }

    private static function ensureUploadDir(string $uploadDir): string
    {
        clearstatcache(true, $uploadDir);
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return 'Could not create uploads folder: ' . $uploadDir;
        }

        @chmod($uploadDir, 0775);
        clearstatcache(true, $uploadDir);
        if (!is_writable($uploadDir)) {
            return 'Uploads folder exists but PHP cannot write to it: ' . $uploadDir;
        }

        return '';
    }

    private static function safePackageName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base) ?? '';
        $base = trim($base, " .\t\n\r\0\x0B");
        return $base !== '' ? $base : ('package_' . date('Ymd_His'));
    }

    private static function uploadErrorText(int $code): string
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

    private static function cleanEngine(string $engine): string
    {
        $engine = strtoupper(trim($engine));
        if (preg_match('/^UE[1-5]$/', $engine) !== 1) {
            throw new \InvalidArgumentException('Unsupported standalone engine label.');
        }
        return $engine;
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
