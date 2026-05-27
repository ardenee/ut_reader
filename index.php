<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$catalogUrl = 'catalog/index.php';
$dashboardUrl = 'catalog/dashboard.php';
$gamesUrl = 'catalog/games.php';
$searchUrl = 'catalog/index.php?page=search';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>UnrealDB - Unreal File Catalog</title>
<style>
body{margin:0;background:#0b1020;color:#eef3ff;font:15px system-ui,Segoe UI,Arial;line-height:1.5}
a{color:#8ab4ff;text-decoration:none}a:hover{text-decoration:underline}
.wrap{max-width:1100px;margin:0 auto;padding:28px 18px}
.hero{background:#121a31;border:1px solid #2a375f;border-radius:18px;padding:26px;margin-bottom:18px}
h1{font-size:34px;margin:0 0 8px}h2{margin-top:0}.muted{color:#9fb0d0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.card{background:#121a31;border:1px solid #2a375f;border-radius:14px;padding:16px}.button{display:inline-block;background:#23325f;border:1px solid #3b5599;color:#eef3ff;padding:10px 14px;border-radius:10px;margin:4px 6px 4px 0}.primary{background:#2a3f78}.mono{font-family:Consolas,monospace}.small{font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <section class="hero">
    <h1>UnrealDB</h1>
    <p class="muted">A catalog for Unreal Engine package files, built to gather Unreal/Unreal Tournament files, inspect imports and exports, and help complete libraries by finding missing dependencies.</p>
    <p>
      <a class="button primary" href="<?= h($catalogUrl) ?>">Open Catalog</a>
      <a class="button" href="<?= h($dashboardUrl) ?>">Admin Dashboard</a>
      <a class="button" href="<?= h($gamesUrl) ?>">Browse Games</a>
      <a class="button" href="<?= h($searchUrl) ?>">Search</a>
    </p>
  </section>

  <section class="grid">
    <div class="card">
      <h2>Build complete libraries</h2>
      <p>Scan UE packages into a database, track package GUIDs and hashes, and identify files that are missing required imports or referenced objects.</p>
    </div>
    <div class="card">
      <h2>Dependency-focused</h2>
      <p>Imports and exports are used to link packages by real object references instead of unreliable filenames, helping group maps, textures, music, sounds, and code packages correctly.</p>
    </div>
    <div class="card">
      <h2>Federation support</h2>
      <p>Deployments can connect to a parent/master catalog, compare inventories, request missing files, and transfer approved files in a controlled way.</p>
    </div>
    <div class="card">
      <h2>Download control</h2>
      <p>Public downloads can use the local site or external shared-provider mirrors, while federation transfers stay separate and controlled by worker limits.</p>
    </div>
  </section>

  <section class="card" style="margin-top:18px">
    <h2>Supported catalog goals</h2>
    <p class="muted">UnrealDB is intended for Unreal file preservation, verification, dependency tracking, and library repair across Unreal Engine game packages.</p>
    <p class="small mono">Main app path: /catalog/</p>
  </section>
</div>
</body>
</html>
