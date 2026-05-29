<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$catalogUrl = 'catalog/index.php';
$dashboardUrl = 'catalog/dashboard.php';
$gamesUrl = 'catalog/games.php';
$searchUrl = 'catalog/index.php?page=search';
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$username = (string)($_SESSION['user']['username'] ?? '');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nav_link(string $label, string $href): void
{
    echo '<a href="' . h($href) . '">' . h($label) . '</a>';
}

function nav_menu(string $label, array $links): void
{
    echo '<details><summary>' . h($label) . '</summary><div class="nav-menu">';
    foreach ($links as $text => $href) {
        nav_link((string)$text, (string)$href);
    }
    echo '</div></details>';
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>UnrealDB - Unreal File Catalog</title>
<link rel="stylesheet" href="catalog/assets/catalog.css">
</head>
<body>
<header class="site-header">
  <div class="brand">
    <a href="<?= h($isAdmin ? $dashboardUrl : $catalogUrl) ?>">
      <span class="brand-mark">U</span>
      <span><strong>UnrealDB</strong><small>package catalog</small></span>
    </a>
  </div>
  <nav class="primary-nav">
    <?php nav_link('Games', $gamesUrl); ?>
    <?php nav_link('Search', $searchUrl); ?>
    <?php if ($isAdmin): ?>
      <span class="nav-sep"></span>
      <?php
      nav_menu('Admin', [
          'Dashboard' => 'catalog/dashboard.php',
          'Library' => 'catalog/library.php',
          'Game Admin' => 'catalog/game-manager.php',
          'Game Profiles' => 'catalog/game-profiles.php',
      ]);
      nav_menu('Sources', [
          'Game Sources' => 'catalog/sources.php',
          'Local Source Scan' => 'catalog/source-scan.php',
          'HTTP Source Scan' => 'catalog/http-source-scan.php',
          'Upload Files' => 'catalog/profiled-upload.php',
      ]);
      nav_menu('Federation', [
          'Federation Admin' => 'catalog/federation/admin.php',
          'Transfers' => 'catalog/transfers.php',
          'Downloads' => 'catalog/download-admin.php',
          'Settings' => 'catalog/federation/settings.php',
      ]);
      nav_link('Logout ' . $username, 'catalog/index.php?page=logout');
      ?>
    <?php else: ?>
      <span class="nav-sep"></span>
      <?php nav_link('Admin Login', 'catalog/index.php?page=login'); ?>
    <?php endif; ?>
  </nav>
</header>

<main>
  <section class="card hero">
    <h1>UnrealDB</h1>
    <p class="muted">A catalog for Unreal Engine package files, built to gather Unreal/Unreal Tournament files, inspect imports and exports, and help complete libraries by finding missing dependencies.</p>
    <p class="hero-actions">
      <a class="button" href="<?= h($catalogUrl) ?>">Open Catalog</a>
      <?php if ($isAdmin): ?>
        <a class="button" href="<?= h($dashboardUrl) ?>">Admin Dashboard</a>
        <a class="button" href="catalog/game-manager.php">Manage Games</a>
        <a class="button" href="catalog/game-profiles.php">Game Profiles</a>
      <?php else: ?>
        <a class="button" href="catalog/index.php?page=login">Admin Login</a>
      <?php endif; ?>
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

  <section class="card">
    <h2>Supported catalog goals</h2>
    <p class="muted">UnrealDB is intended for Unreal file preservation, verification, dependency tracking, and library repair across Unreal Engine game packages.</p>
    <p class="small mono">Main app path: /catalog/</p>
  </section>
</main>
</body>
</html>
