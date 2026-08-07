<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders a simple directory index for the standalone `UE1` parser/reference folder.
 * Why: It is a developer convenience for browsing the files placed beside the standalone parser experiments.
 * Role: Legacy/reference tooling outside the supported `/catalog/` application.
 * Audit: Exact duplicate in this snapshot of `UE2/index.php`, `UE3/index.php`, `new/index.php`; consolidation
 *        candidate after confirming route-specific behavior.
 */

$dir = __DIR__;
$files = scandir($dir);

echo "<!doctype html><html><head><meta charset='utf-8'><title>UT Reader Files</title>";
echo "<style>
body{font-family:system-ui;margin:24px;background:#111;color:#ddd}
a{color:#8cf;text-decoration:none}
table{border-collapse:collapse;width:100%}
td,th{border-bottom:1px solid #333;padding:6px;text-align:left}
.mono{font-family:monospace}
</style>";
echo "</head><body><h1>UT Reader Files</h1>";
echo "<table><thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th></tr></thead><tbody>";

foreach ($files as $file) {
    if ($file === '.' || $file === '..' || $file === '.htaccess') {
        continue;
    }

    $path = $dir . DIRECTORY_SEPARATOR . $file;
    $url = rawurlencode($file);

    echo "<tr>";
    echo "<td><a href='{$url}'>" . htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</a></td>";
    echo "<td class='mono'>" . (is_dir($path) ? 'folder' : 'file') . "</td>";
    echo "<td class='mono'>" . (is_file($path) ? number_format(filesize($path)) : '-') . "</td>";
    echo "<td class='mono'>" . date('Y-m-d H:i:s', filemtime($path)) . "</td>";
    echo "</tr>";
}

echo "</tbody></table></body></html>";
