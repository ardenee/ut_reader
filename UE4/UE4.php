<?php
declare(strict_types=1);
require_once __DIR__ . "/UnrealPackageReader4.php";

$uploadDir = __DIR__ . "/uploads";
$uploadRelDir = "uploads";
$allowedExt = ["uasset", "umap"];

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
function hx($v): string
{
    return sprintf("0x%08X", (int) $v);
}
function safe_package_name(string $name): string
{
    $base = basename(str_replace("\\", "/", rawurldecode($name)));
    $base = preg_replace("/[^A-Za-z0-9._ -]+/", "_", $base) ?? "";
    return trim($base, " .\t\n\r\0\x0B");
}
function upload_file_list(
    string $uploadDir,
    string $uploadRelDir,
    array $allowedExt
): array {
    if (!is_dir($uploadDir)) {
        return [];
    }
    $out = [];
    foreach (scandir($uploadDir) ?: [] as $file) {
        if ($file === "." || $file === "..") {
            continue;
        }
        $full = $uploadDir . DIRECTORY_SEPARATOR . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!is_file($full) || !in_array($ext, $allowedExt, true)) {
            continue;
        }
        $out[] = [
            "name" => $file,
            "rel" => $uploadRelDir . "/" . rawurlencode($file),
            "path" => $full,
            "size" => filesize($full) ?: 0,
            "mtime" => filemtime($full) ?: 0,
        ];
    }
    usort(
        $out,
        static fn(array $a, array $b): int => $b["mtime"] <=> $a["mtime"] ?:
        strcasecmp($a["name"], $b["name"])
    );
    return $out;
}
function resolve_package_path(
    string $fileParam,
    string $uploadDir,
    array $uploadedFiles
): string {
    $root = realpath(__DIR__);
    if ($root === false) {
        return "";
    }
    if ($fileParam !== "") {
        $decoded = rawurldecode($fileParam);
        $base = safe_package_name($decoded);
        if ($base !== "") {
            $uploadCandidate = $uploadDir . DIRECTORY_SEPARATOR . $base;
            if (is_file($uploadCandidate)) {
                return $uploadCandidate;
            }
        }
        $localCandidate =
            __DIR__ .
            DIRECTORY_SEPARATOR .
            ltrim(
                str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $decoded),
                DIRECTORY_SEPARATOR
            );
        $localReal = realpath($localCandidate);
        if (
            $localReal !== false &&
            is_file($localReal) &&
            str_starts_with($localReal, $root . DIRECTORY_SEPARATOR)
        ) {
            return $localReal;
        }
    }
    foreach (["test.uasset", "test.umap"] as $defaultName) {
        $default = __DIR__ . DIRECTORY_SEPARATOR . $defaultName;
        if (is_file($default)) {
            return $default;
        }
    }
    return $uploadedFiles[0]["path"] ?? "";
}
function object_ref_target_id(int $ref): string
{
    return $ref < 0 ? "ref-import-" . abs($ref) : "ref-export-" . $ref;
}
function name_ref_target_id(int $idx): string
{
    return "ref-name-" . $idx;
}
function ref_label(UnrealPackageReader4 $pkg, int $ref): string
{
    if ($ref === 0) {
        return "";
    }
    $name = $pkg->displayNameFromRef($ref);
    return $name !== "" ? $name . "(" . $ref . ")" : "(" . $ref . ")";
}
function ref_link(UnrealPackageReader4 $pkg, int $ref): string
{
    if ($ref === 0) {
        return "";
    }
    return '<a class="ref-link mono" href="#' .
        h(object_ref_target_id($ref)) .
        '">' .
        h(ref_label($pkg, $ref)) .
        "</a>";
}
function name_link(UnrealPackageReader4 $pkg, array $name): string
{
    $idx = (int) ($name["index"] ?? -1);
    $num = (int) ($name["number"] ?? 0);
    $text =
        (string) ($name["text"] ??
            ($idx >= 0 ? $pkg->nameByIndex($idx, $num) : ""));
    if ($idx < 0) {
        return h($text);
    }
    return '<a class="name-link mono" href="#' .
        h(name_ref_target_id($idx)) .
        '">' .
        h($text) .
        '</a><a class="name-tag name-link" href="#' .
        h(name_ref_target_id($idx)) .
        '">#' .
        h($idx) .
        "</a>";
}
function serial_preview(string $packagePath, array $hdr, array $ex): array
{
    $serialSize = (int) ($ex["serialSize"] ?? 0);
    $serialOffset = (int) ($ex["serialOffset"] ?? 0);
    $uassetSize = is_file($packagePath) ? (filesize($packagePath) ?: 0) : 0;
    $uexpPath = (string) ($hdr["uexpPath"] ?? "");
    $totalHeaderSize = (int) ($hdr["totalHeaderSize"] ?? 0);
    if ($serialSize <= 0) {
        return [
            "state" => "none",
            "source" => "",
            "mode" => "",
            "start" => 0,
            "end" => 0,
            "fileSize" => 0,
            "hex" => "",
            "warning" => "",
        ];
    }
    $candidates = [
        [
            "mode" => "uasset:absolute",
            "path" => $packagePath,
            "offset" => $serialOffset,
        ],
    ];
    if ($uexpPath !== "" && is_file($uexpPath)) {
        $candidates[] = [
            "mode" => "uexp:absolute",
            "path" => $uexpPath,
            "offset" => $serialOffset,
        ];
        $candidates[] = [
            "mode" => "uexp:offset-totalHeader",
            "path" => $uexpPath,
            "offset" => $serialOffset - $totalHeaderSize,
        ];
        $candidates[] = [
            "mode" => "uexp:offset-uassetSize",
            "path" => $uexpPath,
            "offset" => $serialOffset - $uassetSize,
        ];
    }
    foreach ($candidates as $c) {
        $path = (string) $c["path"];
        $offset = (int) $c["offset"];
        $fileSize = is_file($path) ? (filesize($path) ?: 0) : 0;
        if ($fileSize <= 0 || $offset < 0 || $offset >= $fileSize) {
            continue;
        }
        $readLen = min(64, $serialSize, $fileSize - $offset);
        $hex = "";
        $fh = @fopen($path, "rb");
        if ($fh !== false) {
            @fseek($fh, $offset);
            $data = $readLen > 0 ? (string) @fread($fh, $readLen) : "";
            @fclose($fh);
            $hex = strtoupper(trim(chunk_split(bin2hex($data), 2, " ")));
        }
        return [
            "state" => "ok",
            "source" => basename($path),
            "mode" => (string) $c["mode"],
            "start" => $offset,
            "end" => $offset + $serialSize,
            "fileSize" => $fileSize,
            "hex" => $hex,
            "warning" =>
                $offset + $serialSize > $fileSize ? "range exceeds file" : "",
        ];
    }
    return [
        "state" => "missing",
        "source" => "",
        "mode" => "",
        "start" => $serialOffset,
        "end" => $serialOffset + $serialSize,
        "fileSize" => $uassetSize,
        "hex" => "",
        "warning" =>
            $uexpPath !== "" && is_file($uexpPath)
                ? "range not found"
                : "missing .uexp",
    ];
}

$uploadedFiles = upload_file_list($uploadDir, $uploadRelDir, $allowedExt);
$fileParam = isset($_GET["file"]) ? (string) $_GET["file"] : "";
$filePath = resolve_package_path($fileParam, $uploadDir, $uploadedFiles);
if ($filePath === "" || !file_exists($filePath)) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "UE4.php: no package file is available.\n";
    echo "Use UE4/upload.php, put a .uasset/.umap into UE4/uploads/, or keep test.uasset beside UE4.php.\n";
    exit();
}
$currentRel = str_starts_with($filePath, $uploadDir . DIRECTORY_SEPARATOR)
    ? $uploadRelDir . "/" . basename($filePath)
    : basename($filePath);
$pkg = new UnrealPackageReader4($filePath);
$hdr = $pkg->getHeader();
$names = $pkg->getNames();
$imports = $pkg->getImports();
$exports = $pkg->getExports();
$issues = $pkg->validatePackage();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UE4 Explorer — <?= h(basename($filePath)) ?></title>
<style>
:root{--b:#cfd7df;--bg:#eef6f8;--panel:#fff;--muted:#f5f7f9;--text:#071629;--sub:#536471;--accent:#0969da}*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);scroll-behavior:smooth}body{font-family:Segoe UI,Tahoma,Arial,sans-serif;font-size:14px}.mono{font-family:Consolas,Menlo,monospace}.raw{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}.workspace{padding:12px}.viewer{width:100%;background:var(--panel);border:1px solid var(--b);min-height:650px}.doc-tabs{display:flex;margin-left:12px}.doc-tab{padding:9px 28px;border:1px solid var(--b);border-bottom:0;border-radius:6px 6px 0 0;background:#fff;font-weight:600}.toolbar{display:grid;grid-template-columns:minmax(420px,1fr) minmax(260px,420px);gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--b);background:#fbfbfb}.file-open-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.file-open-bar form{display:flex;gap:6px;align-items:center;margin:0}.file-select{min-width:320px;padding:6px 8px;border:1px solid #9aa7b1;background:#fff}.btn{border:1px solid var(--b);background:#fff;border-radius:5px;padding:5px 9px;cursor:pointer;text-decoration:none;color:var(--text)}.btn:hover{background:#eef6ff}.package-name{text-align:right;color:#475569}.tabs{display:flex;border-bottom:1px solid var(--b);background:#f8fafb}.tab{border:0;border-right:1px solid var(--b);background:transparent;padding:10px 18px;font-weight:700;cursor:pointer}.tab.active{background:#fff;color:var(--accent);box-shadow:inset 0 -2px 0 var(--accent)}.panel{display:none;padding:16px}.panel.active{display:block}.grid{display:grid;grid-template-columns:190px minmax(0,1fr);gap:10px 18px;max-width:1180px}.label{font-weight:700}.value{border:1px solid var(--b);background:#fbfbfb;padding:6px 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.data{border-collapse:collapse;width:100%;margin-top:12px}.data th,.data td{border:1px solid var(--b);padding:7px 9px;vertical-align:top}.data th{background:var(--muted);text-align:left}.data tr:nth-child(even){background:#fcfdff}.exports th:nth-child(1){width:55px}.exports th:nth-child(5){width:120px}.exports th:nth-child(6){width:90px}.exports th:nth-child(7){width:110px}.ref-tag,.name-tag,.status{display:inline-block;margin-left:4px;border-radius:3px;padding:1px 5px;font-size:12px;border:1px solid #c7dff2;background:#edf6ff;color:#2f6f9f}.ok{background:#dafbe1;border-color:#aceebb;color:#116329}.bad{background:#fff1f0;border-color:#ffccc7;color:#8a1f11}.none{background:#f6f8fa;border-color:#d0d7de;color:#57606a}.warn{background:#fff8f8;border:1px solid #d1242f;padding:8px 12px;margin:12px 0}.ref-link,.name-link{text-decoration:none;color:#0969da}.ref-link:hover,.name-link:hover{text-decoration:underline}details{min-width:310px}summary{cursor:pointer;color:#0969da;font-weight:600}.kv{display:grid;grid-template-columns:115px minmax(0,1fr);gap:3px 8px;margin-top:6px}.hex{white-space:pre-wrap;word-break:break-word;background:#f6f8fa;border:1px solid #d0d7de;padding:6px;margin:6px 0 0;max-height:150px;overflow:auto}.is-target,.target{background:#fff3cd!important;outline:2px solid #f0c36d}.small{font-size:12px;color:#57606a}@media(max-width:1000px){.toolbar{grid-template-columns:1fr}.package-name{text-align:left}}
</style>
<script>
function showPanel(id){document.querySelectorAll('.tab').forEach(e=>e.classList.toggle('active',e.dataset.panel===id));document.querySelectorAll('.panel').forEach(e=>e.classList.toggle('active',e.id===id));}
function clearTargetState(){document.querySelectorAll('.target,.is-target').forEach(e=>e.classList.remove('target','is-target'));}
function jumpToRef(id){if(!id)return;if(id.startsWith('ref-import-'))showPanel('imports-panel');else if(id.startsWith('ref-export-'))showPanel('exports-panel');else if(id.startsWith('ref-name-'))showPanel('names-panel');setTimeout(()=>{const el=document.getElementById(id);if(!el)return;clearTargetState();el.classList.add('target');el.scrollIntoView({behavior:'smooth',block:'center'});},60);}
document.addEventListener('click',function(ev){const a=ev.target.closest&&ev.target.closest('a.ref-link,a.name-link');if(!a)return;const href=a.getAttribute('href')||'';if(href.charAt(0)!=='#')return;ev.preventDefault();jumpToRef(href.substring(1));});
</script>
</head>
<body>
<div class="workspace"><div class="doc-tabs"><div class="doc-tab"><?= h(
    basename($filePath)
) ?></div></div><div class="viewer"><div class="toolbar"><div class="file-open-bar"><form method="get"><select class="file-select" name="file"><?php
foreach ($uploadedFiles as $up): ?><option value="<?= h(
    $up["rel"]
) ?>"<?= basename($filePath) === $up["name"] ? " selected" : "" ?>><?= h(
    $up["name"]
) ?> (<?= h(
     number_format((int) $up["size"])
 ) ?> bytes)</option><?php endforeach;
foreach (["test.uasset", "test.umap"] as $localDefault):
    if (is_file(__DIR__ . "/" . $localDefault)): ?><option value="<?= h(
    $localDefault
) ?>"<?= $currentRel === $localDefault ? " selected" : "" ?>><?= h(
    $localDefault
) ?></option><?php endif;
endforeach;
?></select><button class="btn" type="submit">Open</button><a class="btn" href="upload.php">Upload</a></form></div><span class="package-name"><?= h(
    $currentRel
) ?> (<?= h($pkg->getFileSize()) ?>)</span></div>
<div class="tabs"><button class="tab active" data-panel="summary-panel" onclick="showPanel('summary-panel')">▣ Summary</button><button class="tab" data-panel="names-panel" onclick="showPanel('names-panel')">Names</button><button class="tab" data-panel="imports-panel" onclick="showPanel('imports-panel')">Imports</button><button class="tab" data-panel="exports-panel" onclick="showPanel('exports-panel')">Exports</button></div>
<section id="summary-panel" class="panel active"><h2>UE4 Package Summary</h2><div class="grid"><div class="label">GUID</div><div class="value mono"><?= h(
    $hdr["guid"] ?? ""
) ?></div><div class="label">Legacy Version</div><div class="value mono"><?= h(
    $hdr["legacyFileVersion"] ?? ""
) ?></div><div class="label">Legacy UE3 Version</div><div class="value mono"><?= h(
    $hdr["legacyUE3Version"] ?? ""
) ?></div><div class="label">UE4 Version</div><div class="value mono"><?=
h($hdr["version"] ?? "")
!empty($hdr["unversioned"]) ? " (assumed; unversioned)" : ""
?></div><div class="label">Licensee Version</div><div class="value mono"><?= h(
    $hdr["licenseeVersion"] ?? ""
) ?></div><div class="label">Package Flags</div><div class="value mono"><?= h(
    hx($hdr["packageFlags"] ?? 0)
) ?></div><div class="label">Folder</div><div class="value mono"><?= h(
    $hdr["folderName"] ?? ""
) ?></div><div class="label">Counts</div><div class="value mono">N <?= h(
    $hdr["nameCount"] ?? ""
) ?> / I <?= h($hdr["importCount"] ?? "") ?> / E <?= h(
     $hdr["exportCount"] ?? ""
 ) ?></div><div class="label">Offsets</div><div class="value mono">N <?= h(
    $hdr["nameOffset"] ?? ""
) ?> / I <?= h($hdr["importOffset"] ?? "") ?> / E <?= h(
     $hdr["exportOffset"] ?? ""
 ) ?></div><div class="label">Total Header Size</div><div class="value mono"><?= h(
    $hdr["totalHeaderSize"] ?? ""
) ?></div><div class="label">Asset Registry Offset</div><div class="value mono"><?= h(
    $hdr["assetRegistryDataOffset"] ?? ""
) ?></div><div class="label">Bulk Data Start</div><div class="value mono"><?= h(
    $hdr["bulkDataStartOffset"] ?? ""
) ?></div><div class="label">Preload Dependencies</div><div class="value mono"><?= h(
    $hdr["preloadDependencyCount"] ?? ""
) ?> @ <?= h(
     $hdr["preloadDependencyOffset"] ?? ""
 ) ?></div><div class="label">UEXP Pair</div><div class="value mono"><?= !empty(
    $hdr["hasUexp"]
)
    ? h(basename((string) $hdr["uexpPath"]))
    : "not found" ?></div></div><?php if (
    $issues
): ?><div class="warn"><strong>Validation / Notes</strong><ul><?php foreach (
    $issues
    as $w
): ?><li class="mono raw"><?= h(
    $w
) ?></li><?php endforeach; ?></ul></div><?php endif; ?></section>
<section id="names-panel" class="panel"><h2>Name Map</h2><table class="data"><thead><tr><th>#</th><th>Name</th><th>Offset</th><th>Hashes</th></tr></thead><tbody><?php foreach (
    $names
    as $n
): ?><tr id="<?= h(
    name_ref_target_id((int) $n["index"])
) ?>"><td class="mono"><?= h($n["index"]) ?></td><td class="mono"><?= h(
    $n["name"]
) ?></td><td class="mono"><?= h($n["offset"]) ?></td><td class="mono"><?= h(
    ($n["nonCaseHash"] ?? "") . " / " . ($n["caseHash"] ?? "")
) ?></td></tr><?php endforeach; ?></tbody></table></section>
<section id="imports-panel" class="panel"><h2>Import Map</h2><table class="data"><thead><tr><th>Ref</th><th>Object</th><th>Class Package</th><th>Class</th><th>Outer</th><th>Offset</th></tr></thead><tbody><?php foreach (
    $imports
    as $im
):
    $ref = (int) $im["ref"]; ?><tr id="<?= h(
    object_ref_target_id($ref)
) ?>"><td class="mono"><?= h($ref) ?></td><td><?= name_link(
    $pkg,
    $im["objectName"]
) ?></td><td><?= name_link($pkg, $im["classPackage"]) ?></td><td><?= name_link(
    $pkg,
    $im["className"]
) ?></td><td><?= ref_link(
    $pkg,
    (int) $im["outerIndex"]
) ?></td><td class="mono"><?= h($im["offset"]) ?></td></tr><?php
endforeach; ?></tbody></table></section>
<section id="exports-panel" class="panel"><h2>Export Map</h2><table class="data exports"><thead><tr><th>Ref</th><th>Object</th><th>Class</th><th>Outer</th><th>Serial</th><th>Flags</th><th>Status</th><th>Details</th></tr></thead><tbody><?php foreach (
    $exports
    as $ex
):

    $ref = (int) $ex["ref"];
    $p = serial_preview($filePath, $hdr, $ex);
    $statusClass =
        $p["state"] === "ok" && $p["warning"] === ""
            ? "ok"
            : ($p["state"] === "none"
                ? "none"
                : "bad");
    ?><tr id="<?= h(object_ref_target_id($ref)) ?>"><td class="mono"><?= h(
    $ref
) ?></td><td><?= name_link($pkg, $ex["objectName"]) ?></td><td><?= ref_link(
    $pkg,
    (int) $ex["classIndex"]
) ?></td><td><?= ref_link(
    $pkg,
    (int) $ex["outerIndex"]
) ?></td><td class="mono">size <?= h($ex["serialSize"]) ?><br>offset <?= h(
    $ex["serialOffset"]
) ?></td><td class="mono"><?= h(
    hx($ex["objectFlags"] ?? 0)
) ?></td><td><span class="status <?= h($statusClass) ?>"><?= h(
    $p["warning"] ?: strtoupper($p["state"])
) ?></span></td><td><details><summary>Details</summary><div class="kv small"><div>Template</div><div><?= ref_link(
    $pkg,
    (int) $ex["templateIndex"]
) ?:
    '<span class="status none">none</span>' ?></div><div>Source</div><div class="mono"><?= h(
    $p["source"] ?: "-"
) ?></div><div>Mode</div><div class="mono"><?= h(
    $p["mode"] ?: "-"
) ?></div><div>Local range</div><div class="mono"><?= h($p["start"]) ?>..<?= h(
    $p["end"]
) ?></div><div>File size</div><div class="mono"><?= h(
    $p["fileSize"]
) ?></div><div>Booleans</div><div>forced <?= !empty($ex["forcedExport"])
    ? "Y"
    : "N" ?>, client <?= !empty($ex["notForClient"])
    ? "no"
    : "yes" ?>, server <?= !empty($ex["notForServer"])
    ? "no"
    : "yes" ?>, asset <?= $ex["isAsset"] === null
    ? "?"
    : (!empty($ex["isAsset"])
        ? "Y"
        : "N") ?></div><div>Package flags</div><div class="mono"><?= h(
    hx($ex["packageFlags"] ?? 0)
) ?></div><div>Package GUID</div><div class="mono"><?= h(
    $ex["packageGuid"] ?? ""
) ?></div></div><?php
if ($p["hex"] !== ""): ?><pre class="hex"><?= h($p["hex"]) ?></pre><?php endif;
if (!empty($ex["preload"])): ?><pre class="hex"><?= h(
    json_encode($ex["preload"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
) ?></pre><?php endif;
?></details></td></tr><?php
endforeach; ?></tbody></table></section>
</div></div>
</body>
</html>
