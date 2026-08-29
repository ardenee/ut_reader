<?php
/**
 * Public UnrealDB landing page.
 */
declare(strict_types=1);

require_once __DIR__ . '/catalog/lib/CatalogSupport.php';

catalog_start_session();

$gameStorage = [];
$totalCatalogFiles = 0;
$totalCatalogBytes = 0;
$databaseBytes = 0;
$fileRecordStats = [
    'file_count' => 0,
    'verified_count' => 0,
    'unverified_count' => 0,
    'failed_count' => 0,
    'duplicate_count' => 0,
];
try {
    $config = catalog_config();
    $db = catalog_db($config);
    $gameStorage = catalog_all(
        $db,
        'SELECT g.id,g.name,COALESCE(s.verified_count,0) file_count,COALESCE(s.verified_size,0) storage_bytes '
        . 'FROM ue_games g LEFT JOIN ue_game_catalog_stats s ON s.game_id=g.id ORDER BY g.name'
    );

    foreach ($gameStorage as $row) {
        $totalCatalogFiles += max(0, (int)($row['file_count'] ?? 0));
        $totalCatalogBytes += max(0, (int)($row['storage_bytes'] ?? 0));
    }

    $statusRows = catalog_all(
        $db,
        'SELECT scan_status,COUNT(*) record_count FROM ue_files GROUP BY scan_status'
    );
    foreach ($statusRows as $row) {
        $status = strtolower(trim((string)($row['scan_status'] ?? '')));
        $count = max(0, (int)($row['record_count'] ?? 0));
        if (array_key_exists($status . '_count', $fileRecordStats)) {
            $fileRecordStats[$status . '_count'] = $count;
        }
        $fileRecordStats['file_count'] += $count;
    }

    $databaseName = trim((string)$db->query('SELECT DATABASE()')->fetchColumn());
    if ($databaseName !== '') {
        $sizeStatement = $db->prepare(
            'SELECT COALESCE(SUM(data_length + index_length),0) '
            . 'FROM information_schema.tables WHERE table_schema=?'
        );
        $sizeStatement->execute([$databaseName]);
        $databaseBytes = max(0, (int)($sizeStatement->fetchColumn() ?: 0));
    }
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] landing storage summary unavailable: ' . $error->getMessage());
    $gameStorage = [];
}

catalog_head('UnrealDB - Unreal File Catalog');
?>
<section class="card" style="border:1px solid #b91c1c;background:rgba(127,29,29,.16)">
  <h2>Storage outage</h2>
  <p><strong>UnrealDB's primary storage drive has failed.</strong></p>
  <p>The file collection is currently unavailable while the storage system is being recovered and rebuilt. Downloads, contributions and other features that require stored files may not work during this outage.</p>
  <p class="muted">The database and catalog may remain available for browsing, but restoring file storage will take some time. Thank you for your patience while the service is brought back online.</p>
</section>

<section class="card hero">
  <h1>UnrealDB</h1>
  <p class="muted">A catalog for Unreal Engine package files, built to gather Unreal and Unreal Tournament files, inspect imports and exports, and help complete libraries by finding missing dependencies.</p>
  <p class="hero-actions">
    <a class="button" href="catalog/index.php">Open Catalog</a>
    <a class="button" href="catalog/public-upload.php">Contribute files</a>
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

<section class="card">
  <h2>Active development</h2>
  <p>UnrealDB is currently under active development and has been made publicly available as an early preview.</p>
  <p class="muted">Some functions are incomplete, unavailable, or may change as the catalog, preservation tooling, dependency analysis and public contribution workflow continue to develop.</p>
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
  <p>UnrealDB is intended for Unreal file preservation, verification, dependency tracking, and library repair across Unreal Engine game packages.</p>
  <p>You can help expand the project by contributing your own Unreal and Unreal Tournament package files. The public uploader checks files in your browser first and avoids transferring exact files that UnrealDB already holds.</p>
  <p><a class="button" href="catalog/public-upload.php">Contribute Unreal files</a></p>
</section>

<section class="card">
  <h2>Support the project</h2>
  <p>UnrealDB is currently hosted locally and, because of resource limitations, the project does not yet have the storage redundancy that a preservation catalog should have.</p>
  <p><strong>The project is not looking for money at this time.</strong> The immediate priority is storage capacity and redundancy, so hard drives are much more useful to the project right now.</p>
  <p>There is no intention to burden anyone or ask people to buy new hardware. If you already have a hard drive lying around &mdash; including a used drive &mdash; and you know it is reliable enough to be put back into service, it would be very welcome and could directly help expand the catalog or add another copy of preserved files.</p>
  <p class="muted">The main infrastructure priority is adding redundancy and capacity so the growing collection is better protected against hardware failure.</p>
  <p><a class="button" href="catalog/feedback.php">Contact us about sending a drive</a></p>
</section>

<section class="card">
  <h2>Catalog file storage used</h2>
  <?php if ($gameStorage !== []): ?>
    <div class="grid">
      <div class="stat">
        <h2><?= catalog_h(catalog_bytes($totalCatalogBytes)) ?></h2>
        <p>Total verified file storage used</p>
        <p class="muted small"><?= catalog_h(number_format($totalCatalogFiles)) ?> verified files</p>
      </div>
      <div class="stat">
        <h2><?= catalog_h(catalog_bytes($databaseBytes)) ?></h2>
        <p>Database size</p>
        <p class="muted small">Data + indexes currently allocated by MySQL</p>
      </div>
      <div class="stat">
        <h2><?= catalog_h(number_format((int)$fileRecordStats['file_count'])) ?></h2>
        <p>Total file records</p>
        <p class="muted small">All catalog file states</p>
      </div>
      <div class="stat">
        <h2><?= catalog_h(number_format((int)$fileRecordStats['verified_count'])) ?></h2>
        <p>Verified records</p>
        <p class="muted small">Accepted into the catalog</p>
      </div>
      <div class="stat">
        <h2><?= catalog_h(number_format((int)$fileRecordStats['unverified_count'])) ?></h2>
        <p>Unverified records</p>
        <p class="muted small">Awaiting review/import decisions</p>
      </div>
      <div class="stat">
        <h2><?= catalog_h(number_format((int)$fileRecordStats['failed_count'])) ?></h2>
        <p>Failed records</p>
        <p class="muted small">Recorded package-processing failures</p>
      </div>
      <div class="stat">
        <h2><?= catalog_h(number_format((int)$fileRecordStats['duplicate_count'])) ?></h2>
        <p>Duplicate records</p>
        <p class="muted small">Catalogued duplicate identities</p>
      </div>
      <?php foreach ($gameStorage as $row): ?>
        <div class="stat">
          <h2><?= catalog_h(number_format((int)($row['file_count'] ?? 0))) ?></h2>
          <p><?= catalog_h((string)($row['name'] ?? 'Game')) ?></p>
          <p class="muted small"><?= catalog_h(catalog_bytes(max(0, (int)($row['storage_bytes'] ?? 0)))) ?> used</p>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted small">File-storage figures are the summed sizes of verified catalog files. Database size is MySQL's currently allocated data + index size.</p>
  <?php else: ?>
    <p class="muted">Catalog storage totals are temporarily unavailable.</p>
  <?php endif; ?>
</section>
<?php
catalog_foot();
