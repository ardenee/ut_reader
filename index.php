<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/catalog/lib/CatalogSupport.php';

catalog_head('UnrealDB - Unreal File Catalog');
?>
<section class="card hero">
  <h1>UnrealDB</h1>
  <p class="muted">A catalog for Unreal Engine package files, built to gather Unreal and Unreal Tournament files, inspect imports and exports, and help complete libraries by finding missing dependencies.</p>
  <p class="hero-actions">
    <a class="button" href="catalog/index.php">Open Catalog</a>
    <?php if (catalog_support_is_admin()): ?>
      <a class="button" href="catalog/dashboard.php">Admin Dashboard</a>
      <a class="button" href="catalog/game-manager.php">Manage Games</a>
      <a class="button" href="catalog/game-profiles.php">Game Profiles</a>
    <?php else: ?>
      <a class="button" href="catalog/index.php?page=login">Admin Login</a>
    <?php endif; ?>
    <a class="button" href="catalog/games.php">Browse Games</a>
    <a class="button" href="catalog/index.php?page=search">Search</a>
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

<section class="card">
  <h2>Supported catalog goals</h2>
  <p class="muted">UnrealDB is intended for Unreal file preservation, verification, dependency tracking, and library repair across Unreal Engine game packages.</p>
  <p class="small mono">Main app path: /catalog/</p>
</section>
<?php
catalog_foot();
