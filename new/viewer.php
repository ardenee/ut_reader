<?php
// viewer.php

require_once __DIR__ . '/UnrealPackageReader.php';   // adjust name if needed

use function htmlspecialchars as h;

function fmt($x) {
    return is_array($x) ? '<pre>'.h(print_r($x,true)).'</pre>' : h($x);
}

// --- handle upload ---
$uploadPath = null;
if (!empty($_FILES['file']['tmp_name'])) {
    $name = basename($_FILES['file']['name']);
    $uploadPath = __DIR__ . "/uploads/" . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
    @mkdir(__DIR__ . '/uploads', 0777, true);
    move_uploaded_file($_FILES['file']['tmp_name'], $uploadPath);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Unreal Package Loader</title>
<style>
body { font-family: system-ui, sans-serif; background:#111; color:#ddd; margin:0; }
h1,h2,h3 { color:#9cf; margin:0.6em 0 0.3em; }
a { color:#6cf; text-decoration:none; }
section { border-top:1px solid #333; padding:1em; }
pre { background:#000; color:#0f0; padding:0.5em; overflow:auto; }
details { background:#181818; margin:0.5em 0; padding:0.5em 0.8em; border-radius:8px; }
summary { font-weight:bold; color:#9cf; cursor:pointer; }
table { border-collapse:collapse; width:100%; color:#ccc; }
th,td { border-bottom:1px solid #333; padding:0.3em 0.6em; text-align:left; }
tr:hover td { background:#222; }
.mono { font-family: monospace; }
small { color:#888; }
input[type=file] { color:#fff; }
</style>
</head>
<body>
<h1>Unreal Package Loader</h1>

<form method="post" enctype="multipart/form-data">
  <label>Upload Unreal package (.u / .utx / .usx / .ukx): </label>
  <input type="file" name="file" required>
  <input type="submit" value="Load">
</form>

<?php
if ($uploadPath && file_exists($uploadPath)) {
    echo "<section><h2>Loaded File</h2><b>".h(basename($uploadPath))."</b> (".number_format(filesize($uploadPath))." bytes)</section>";

    try {
        $data = file_get_contents($uploadPath);
        $pkg  = new UnrealPackageReader($data);

        echo "<section><h2>Header</h2>";
        echo fmt($pkg->header ?? []);
        echo "</section>";

        echo "<section><h2>Names</h2><small>".count($pkg->names ?? [])." entries</small><br>";
        foreach ($pkg->names ?? [] as $i=>$n) echo "$i. ".h($n)."<br>";
        echo "</section>";

        echo "<section><h2>Imports</h2><small>".count($pkg->imports ?? [])." entries</small>";
        echo fmt($pkg->imports ?? []);
        echo "</section>";

        echo "<section><h2>Exports</h2><small>".count($pkg->exports ?? [])." entries</small>";

        foreach ($pkg->exports ?? [] as $i => $e) {
            $cls = h($pkg->exportClassName($i));
            $nm  = h($e['objectName'] ?? '');
            $pkgName = h($e['packageName'] ?? '');
            echo "<details><summary>[$i] <b>$nm</b> <small>($cls / $pkgName)</small></summary>";

            // Show export basic info
            echo "<table><tr><td>Offset</td><td class='mono'>".$e['serialOffset']."</td></tr>";
            echo "<tr><td>Size</td><td class='mono'>".$e['serialSize']."</td></tr></table>";

            // --- class-specific peekers ---
            switch ($cls) {
                case 'Function':
                    $info = $pkg->peekFunction($i);
                    echo "<h4>Function</h4>".fmt($info);
                    break;
                case 'State':
                    $info = $pkg->peekState($i);
                    echo "<h4>State</h4>".fmt($info);
                    break;
                case 'Class':
                    $info = $pkg->peekClass($i);
                    echo "<h4>Class</h4>".fmt($info);
                    break;
                case 'Texture':
                    $info = $pkg->readTexture($i);
                    echo "<h4>Texture</h4>".fmt($info);
                    break;
                case 'Palette':
                    $info = $pkg->readPalette($i);
                    echo "<h4>Palette</h4>".fmt($info);
                    break;
                case 'TextBuffer':
                    $info = $pkg->readTextBuffer($i);
                    echo "<h4>TextBuffer</h4>".fmt($info);
                    break;
                case 'Sound':
                    $info = $pkg->readSound($i);
                    echo "<h4>Sound</h4>".fmt($info);
                    break;
                case 'Music':
                    $info = $pkg->readMusic($i);
                    echo "<h4>Music</h4>".fmt($info);
                    break;
                case 'Mesh':
                    $info = $pkg->readMesh($i);
                    echo "<h4>Mesh</h4>".fmt($info);
                    break;
                case 'LodMesh':
                    $lod = $pkg->readLodMeshFull($i);
                    echo "<h4>LodMesh (LOD array)</h4>".fmt($lod);
                    $geo = $pkg->readLodMeshGeometry($i);
                    echo "<h4>Geometry</h4>".fmt($geo);
                    $sum = $pkg->summarizeLodMeshSections($i);
                    echo "<h4>Sections</h4>".fmt($sum);
                    $norm = $pkg->computeLodMeshNormals($geo);
                    echo "<h4>Normals</h4>".fmt(array_slice($norm,0,5));
                    $outPng = __DIR__."/uploads/preview_$i.png";
                    if ($pkg->renderLodMeshPNG($i, $outPng, ['mode'=>'flat','size'=>[480,480]])) {
                        echo "<h4>Preview</h4><img src='uploads/".basename($outPng)."' style='max-width:480px;border:1px solid #444;border-radius:6px'>";
                    }
                    break;
                case 'SkeletalMesh':
                    $info = $pkg->readSkeletalMesh($i);
                    echo "<h4>SkeletalMesh</h4>".fmt($info);
                    break;
                case 'Animation':
                    $info = $pkg->readAnimation($i);
                    echo "<h4>Animation</h4>".fmt($info);
                    break;
                default:
                    // Fallback: raw property dump or script preview if present
                    $body = $pkg->exportBodyReader($i);
                    if ($body) {
                        echo "<pre class='mono'>Raw body len=".($body->remaining())."</pre>";
                    }
            }

            echo "</details>";
        }
        echo "</section>";

    } catch (Throwable $ex) {
        echo "<section style='color:#f88'><b>Error:</b> ".h($ex->getMessage())."</section>";
    }
}
?>
</body>
</html>
