<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$configFile = __DIR__ . '/config.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function u(array $p = []): string { return 'index.php' . ($p ? '?' . http_build_query($p) : ''); }
function clean_name(string $s): string { return trim(str_replace(["\0", '/', "\\"], ['', '.', '.'], $s)); }
function slug_text(string $s): string { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? ''; return trim($s, '-') ?: 'item'; }
function bytes_fmt(int $b): string { $u = ['B','KB','MB','GB','TB']; $n = $b; $i = 0; while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; } return ($i ? number_format($n, 2) : (string)$b) . ' ' . $u[$i]; }
function admin(): bool { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
function need_admin(): void { if (!admin()) { header('Location: ' . u(['page' => 'login'])); exit; } }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { throw new RuntimeException('Bad CSRF token'); } }
function join_path_parts(array $parts): string { return implode('.', array_values(array_filter(array_map('clean_name', $parts), static fn($v) => $v !== ''))); }

function page_head(string $title, array $config = []): void
{
    $siteName = $config['site_name'] ?? 'Unreal File Catalog';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . '</title>';
    echo '<style>body{margin:0;background:#0b1020;color:#eef3ff;font:14px system-ui,Segoe UI,Arial}a{color:#8ab4ff;text-decoration:none}a:hover{text-decoration:underline}header{background:#090d19;border-bottom:1px solid #2a375f;padding:14px 18px;display:flex;gap:16px;flex-wrap:wrap;align-items:center}nav a{background:#17213d;padding:6px 9px;border-radius:8px;margin-right:6px}main{max-width:1280px;margin:auto;padding:18px}.card{background:#121a31;border:1px solid #2a375f;border-radius:14px;padding:16px;margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.stat{background:#17213d;border:1px solid #2a375f;border-radius:12px;padding:12px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #2a375f;padding:8px;text-align:left;vertical-align:top}th{background:#17213d}.muted{color:#9fb0d0}.mono{font-family:Consolas,monospace}.button,button{background:#23325f;border:1px solid #3b5599;color:#eef3ff;padding:8px 11px;border-radius:8px;cursor:pointer}.danger{background:#4a1b25;border-color:#8c2e3e}input,select,textarea{background:#0d1428;color:#eef3ff;border:1px solid #2a375f;border-radius:8px;padding:8px}.msg{padding:10px 12px;border-radius:10px;margin-bottom:14px;background:#102744;border:1px solid #275a92}.err{background:#3b121b;border-color:#863040}.dep{display:inline-block;font-size:12px;padding:2px 7px;border-radius:999px;border:1px solid #2a375f;background:#17213d;margin:2px}.resolved{border-color:#1d8f54}.missing{border-color:#a63b43}.common{border-color:#5c6688}.package_only{border-color:#b48a2a}.scroll{overflow:auto;max-height:560px}.path{word-break:break-all}.small{font-size:12px}</style>';
    echo '</head><body><header><strong>' . h($siteName) . '</strong><nav><a href="' . h(u()) . '">Games</a><a href="' . h(u(['page' => 'search'])) . '">Search</a>';
    if (admin()) {
        echo '<a href="' . h(u(['page' => 'admin'])) . '">Admin</a><a href="' . h(u(['page' => 'logout'])) . '">Logout ' . h($_SESSION['user']['username'] ?? '') . '</a>';
    } else {
        echo '<a href="' . h(u(['page' => 'login'])) . '">Admin Login</a>';
    }
    echo '</nav></header><main>';
    if (isset($_SESSION['flash'])) { echo '<div class="msg">' . h($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); }
}

function page_foot(): void { echo '</main></body></html>'; }
function flash(string $url, string $message): void { $_SESSION['flash'] = $message; header('Location: ' . $url); exit; }

if (!is_file($configFile)) {
    page_head('Setup Required');
    echo '<div class="card"><h1>Setup required</h1><p>The catalog config file does not exist yet.</p><p>Copy:</p><pre class="mono">catalog/config.example.php</pre><p>to:</p><pre class="mono">catalog/config.php</pre><p>Then edit the DB settings and import <code>catalog/install.sql</code>.</p></div>';
    page_foot();
    exit;
}

$config = require $configFile;

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    if (!class_exists('PDO')) { throw new RuntimeException('PHP PDO is not available.'); }
    if (!extension_loaded('pdo_mysql')) { throw new RuntimeException('Missing PHP extension: pdo_mysql. Loaded PDO drivers: ' . implode(', ', PDO::getAvailableDrivers())); }
    $d = $config['db'] ?? [];
    foreach (['host','port','database','username','password'] as $k) {
        if (!array_key_exists($k, $d)) { throw new RuntimeException('Missing DB config value: db.' . $k); }
    }
    $dsn = 'mysql:host=' . $d['host'] . ';port=' . (int)$d['port'] . ';dbname=' . $d['database'] . ';charset=' . ($d['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    return $pdo;
}

function one(PDO $db, string $sql, array $args = []): ?array { $s = $db->prepare($sql); $s->execute($args); $r = $s->fetch(); return $r ?: null; }
function allq(PDO $db, string $sql, array $args = []): array { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(); }
function runq(PDO $db, string $sql, array $args = []): int { $s = $db->prepare($sql); $s->execute($args); return $s->rowCount(); }

function load_reader_class(array $config, string $engineKey): string
{
    $readerConfig = $config['engine_readers'][$engineKey] ?? [];
    $rel = $readerConfig['reader'] ?? '';
    $path = realpath(__DIR__ . '/' . $rel);
    if (!$path || !is_file($path)) { throw new RuntimeException('Reader not found for ' . $engineKey . ': ' . $rel); }

    require_once $path;

    $candidates = [];
    if (!empty($readerConfig['class'])) { $candidates[] = (string)$readerConfig['class']; }
    $candidates[] = match ($engineKey) {
        'UE4' => 'UnrealPackageReader4',
        default => 'UnrealPackageReader',
    };
    $candidates[] = 'UnrealPackageReader';
    $candidates[] = 'UnrealPackageReader4';

    foreach (array_unique($candidates) as $class) {
        if ($class !== '' && class_exists($class, false)) { return $class; }
    }

    throw new RuntimeException('Reader file loaded for ' . $engineKey . ', but no supported reader class was found. Tried: ' . implode(', ', array_unique($candidates)));
}

function split_reader_issues(array $issues): array
{
    $fatal = [];
    $notes = [];

    foreach ($issues as $issue) {
        $text = trim((string)$issue);
        if ($text === '') { continue; }

        if (str_starts_with($text, 'Package is unversioned; using assumed UE4 version ')) {
            $notes[] = $text;
            continue;
        }

        $fatal[] = $text;
    }

    return [$fatal, $notes];
}

function ref_path(int $ref, array $imports, array $exports, array &$cache, array $seen = []): string
{
    if ($ref === 0) { return ''; }
    if (isset($cache[$ref])) { return $cache[$ref]; }
    if (isset($seen[$ref])) { return ''; }
    $seen[$ref] = true;

    if ($ref < 0) {
        $row = $imports[-$ref - 1] ?? null;
        if (!$row) { return ''; }
        $outer = (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['outer'] ?? 0);
        $name = (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? ''));
        return $cache[$ref] = join_path_parts([ref_path($outer, $imports, $exports, $cache, $seen), $name]);
    }

    $row = $exports[$ref - 1] ?? null;
    if (!$row) { return ''; }
    $outer = (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0);
    $name = (string)($row['objectNameText'] ?? '');
    return $cache[$ref] = join_path_parts([ref_path($outer, $imports, $exports, $cache, $seen), $name]);
}

function rebuild_dependencies(PDO $db, array $config, int $fileId): void
{
    runq($db, 'DELETE FROM ue_dependencies WHERE file_id=?', [$fileId]);
    $file = one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) { return; }

    $insert = $db->prepare('INSERT INTO ue_dependencies(file_id,import_id,required_package,required_object_path,resolved_file_id,resolved_export_id,status) VALUES(?,?,?,?,?,?,?)');
    foreach (allq($db, 'SELECT * FROM ue_imports WHERE file_id=?', [$fileId]) as $imp) {
        $status = 'missing';
        $resolvedFile = null;
        $resolvedExport = null;
        if ((int)$imp['is_common'] === 1) {
            $status = 'common';
        } elseif ($imp['relative_object_path'] === '') {
            $match = one($db, 'SELECT id FROM ue_files WHERE game_id=? AND package_name=? AND id<>? ORDER BY uploaded_at DESC LIMIT 1', [$file['game_id'], $imp['root_package'], $fileId]);
            if ($match) { $status = 'package_only'; $resolvedFile = (int)$match['id']; }
        } else {
            $match = one($db, 'SELECT e.id export_id, f.id file_id FROM ue_exports e JOIN ue_files f ON f.id=e.file_id WHERE f.game_id=? AND e.full_path=? AND f.id<>? ORDER BY f.uploaded_at DESC LIMIT 1', [$file['game_id'], $imp['full_path'], $fileId]);
            if ($match) { $status = 'resolved'; $resolvedFile = (int)$match['file_id']; $resolvedExport = (int)$match['export_id']; }
        }
        $insert->execute([$fileId, $imp['id'], $imp['root_package'], $imp['full_path'], $resolvedFile, $resolvedExport, $status]);
    }
}

function rebuild_game(PDO $db, array $config, int $gameId): void
{
    foreach (allq($db, 'SELECT id FROM ue_files WHERE game_id=?', [$gameId]) as $file) { rebuild_dependencies($db, $config, (int)$file['id']); }
}

function store_failed_upload(array $config, string $tmp, string $originalName, string $gameSlug, string $reason): void
{
    if (!is_file($tmp)) { return; }
    $dir = rtrim($config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . slug_text($gameSlug) . '/unverified';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($originalName));
    @rename($tmp, $dir . '/' . $name);
    @file_put_contents($dir . '/' . $name . '.txt', $reason);
}

function scan_uploaded_file(PDO $db, array $config, int $gameId, string $tmp, string $originalName, ?int $userId): array
{
    $game = one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) { throw new RuntimeException('Game not found'); }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $config['allowed_extensions'], true)) { throw new RuntimeException('Extension not allowed: ' . $ext); }

    $size = filesize($tmp) ?: 0;
    if ($size <= 0 || $size > (int)$config['max_upload_bytes']) { throw new RuntimeException('Bad file size: ' . bytes_fmt((int)$size)); }

    $md5 = md5_file($tmp);
    $sha1 = sha1_file($tmp);
    if (!$md5 || !$sha1) { throw new RuntimeException('Could not hash file'); }

    $duplicate = one($db, 'SELECT id, original_name FROM ue_files WHERE md5=?', [$md5]);
    if ($duplicate) { return ['duplicate', (int)$duplicate['id'], 'Duplicate MD5: ' . $duplicate['original_name']]; }

    $readerClass = load_reader_class($config, $game['engine_key']);
    $pkg = new $readerClass($tmp);
    $issues = method_exists($pkg, 'validatePackage') ? $pkg->validatePackage() : (method_exists($pkg, 'getDebugErrors') ? $pkg->getDebugErrors() : []);
    [$fatalIssues, $scanNotes] = split_reader_issues($issues);
    if ($fatalIssues) { throw new RuntimeException(implode("\n", $fatalIssues)); }

    foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
        if (!method_exists($pkg, $method)) { throw new RuntimeException('Reader is missing method: ' . $method); }
    }

    $header = $pkg->getHeader();
    $names = $pkg->getNames();
    $imports = $pkg->getImports();
    $exports = $pkg->getExports();
    $packageName = clean_name(pathinfo($originalName, PATHINFO_FILENAME));
    $scanNotesText = $scanNotes ? implode("\n", $scanNotes) : null;

    $dir = rtrim($config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . slug_text($game['slug']) . '/verified';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new RuntimeException('Could not create storage folder: ' . $dir); }

    $storedName = $md5 . '.' . $ext;
    $dest = $dir . '/' . $storedName;
    if (!rename($tmp, $dest)) { throw new RuntimeException('Could not store upload'); }
    $relativePath = 'storage/games/' . slug_text($game['slug']) . '/verified/' . $storedName;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO ue_files(game_id,package_name,original_name,stored_name,relative_path,extension,file_size,md5,sha1,package_guid,package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$gameId, $packageName, $originalName, $storedName, $relativePath, $ext, $size, $md5, $sha1, (string)($header['guid'] ?? ''), (int)($header['version'] ?? 0), (int)($header['licensee'] ?? ($header['licenseeVersion'] ?? 0)), count($names), count($imports), count($exports), 'verified', $scanNotesText, $userId]);
        $fileId = (int)$db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES(?,?,?,?)');
        foreach ($names as $i => $name) { $stmt->execute([$fileId, $i, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null]); }

        $cache = [];
        $common = array_map('strtolower', $config['common_packages'] ?? []);
        $stmt = $db->prepare('INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ($imports as $i => $imp) {
            $full = ref_path(-($i + 1), $imports, $exports, $cache);
            $parts = $full !== '' ? explode('.', $full) : [];
            $root = $parts[0] ?? '';
            $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $object = (string)($imp['objectNameText'] ?? ($imp['ObjectName']['text'] ?? ''));
            $classPackage = (string)($imp['classPackageText'] ?? ($imp['ClassPackage']['text'] ?? ''));
            $className = (string)($imp['classNameText'] ?? ($imp['ClassName']['text'] ?? ''));
            $outer = (int)($imp['outerIndex'] ?? $imp['OuterIndex'] ?? $imp['outer'] ?? 0);
            $stmt->execute([$fileId, $i, $classPackage, $className, $object, $outer, $full, $root, $relative, in_array(strtolower($root), $common, true) ? 1 : 0]);
        }

        $stmt = $db->prepare('INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ($exports as $i => $exp) {
            $local = ref_path($i + 1, $imports, $exports, $cache);
            $classRef = (int)($exp['classIndex'] ?? $exp['class'] ?? 0);
            $className = $classRef ? ref_path($classRef, $imports, $exports, $cache) : '';
            $outer = (int)($exp['outerIndex'] ?? $exp['packageIndex'] ?? $exp['outer'] ?? 0);
            $stmt->execute([$fileId, $i, $className, (string)($exp['objectNameText'] ?? ''), $outer, $local, join_path_parts([$packageName, $local]), isset($exp['objectFlags']) ? (int)$exp['objectFlags'] : null, isset($exp['serialSize']) ? (int)$exp['serialSize'] : null, isset($exp['serialOffset']) ? (int)$exp['serialOffset'] : null]);
        }

        rebuild_dependencies($db, $config, $fileId);
        $db->commit();
        rebuild_game($db, $config, $gameId);
        return ['verified', $fileId, 'Uploaded and scanned'];
    } catch (Throwable $e) {
        $db->rollBack();
        @unlink($dest);
        throw $e;
    }
}

try {
    $db = db($config);
    $page = $_GET['page'] ?? 'home';

    if ($page === 'logout') { session_destroy(); header('Location: ' . u()); exit; }

    if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $count = (int)(one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        if ($count === 0) {
            $username = trim((string)$_POST['username']);
            $password = (string)$_POST['password'];
            if ($username === '' || strlen($password) < 8) { throw new RuntimeException('Username required and password must be at least 8 characters'); }
            runq($db, 'INSERT INTO ue_users(username,password_hash,role) VALUES(?,?,?)', [$username, password_hash($password, PASSWORD_DEFAULT), 'admin']);
        }
        $user = one($db, 'SELECT * FROM ue_users WHERE username=?', [trim((string)$_POST['username'])]);
        if (!$user || !password_verify((string)$_POST['password'], $user['password_hash'])) { throw new RuntimeException('Invalid login'); }
        $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']];
        flash(u(['page' => 'admin']), 'Logged in');
    }

    if ($page === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        need_admin();
        check_csrf();
        $gameId = (int)$_POST['game_id'];
        $game = one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) { throw new RuntimeException('Game not found'); }
        $ok = $dup = $bad = 0;
        $messages = [];
        foreach ($_FILES['files']['tmp_name'] ?? [] as $i => $tmp) {
            $name = (string)($_FILES['files']['name'][$i] ?? 'upload.bin');
            $err = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) { $bad++; $messages[] = $name . ': upload error ' . $err; continue; }
            try {
                $result = scan_uploaded_file($db, $config, $gameId, $tmp, $name, $_SESSION['user']['id'] ?? null);
                if ($result[0] === 'duplicate') { $dup++; } else { $ok++; }
                $messages[] = $name . ': ' . $result[2];
            } catch (Throwable $e) {
                $bad++;
                store_failed_upload($config, $tmp, $name, $game['slug'], $e->getMessage());
                $messages[] = $name . ': failed - ' . $e->getMessage();
            }
        }
        flash(u(['page' => 'game', 'id' => $gameId]), 'Upload complete. Verified=' . $ok . ' Duplicate=' . $dup . ' Failed=' . $bad . '. ' . implode(' | ', array_slice($messages, 0, 5)));
    }

    if ($page === 'save_game' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        need_admin();
        check_csrf();
        runq($db, 'INSERT INTO ue_games(name,slug,engine_key,description) VALUES(?,?,?,?)', [trim((string)$_POST['name']), slug_text((string)$_POST['slug']), (string)$_POST['engine_key'], trim((string)$_POST['description'])]);
        flash(u(['page' => 'admin']), 'Game saved');
    }

    if ($page === 'save_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        need_admin();
        check_csrf();
        $id = (int)$_POST['id'];
        runq($db, 'UPDATE ue_files SET package_name=? WHERE id=?', [clean_name((string)$_POST['package_name']), $id]);
        $file = one($db, 'SELECT game_id FROM ue_files WHERE id=?', [$id]);
        if ($file) { rebuild_game($db, $config, (int)$file['game_id']); }
        flash(u(['page' => 'file', 'id' => $id]), 'Package name saved and links rebuilt');
    }

    if ($page === 'delete_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        need_admin();
        check_csrf();
        $id = (int)$_POST['id'];
        $file = one($db, 'SELECT * FROM ue_files WHERE id=?', [$id]);
        if ($file) {
            runq($db, 'DELETE FROM ue_files WHERE id=?', [$id]);
            $path = __DIR__ . '/' . $file['relative_path'];
            if (is_file($path)) { @unlink($path); }
            rebuild_game($db, $config, (int)$file['game_id']);
        }
        flash(u(['page' => 'admin']), 'File deleted');
    }

    if ($page === 'download') {
        $id = (int)($_GET['id'] ?? 0);
        $file = one($db, 'SELECT * FROM ue_files WHERE id=?', [$id]);
        if (!$file) { throw new RuntimeException('File not found'); }
        $path = realpath(__DIR__ . '/' . $file['relative_path']);
        $root = realpath(rtrim($config['storage_path'], DIRECTORY_SEPARATOR));
        if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) { throw new RuntimeException('Stored file missing'); }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
        readfile($path);
        exit;
    }

    page_head($config['site_name'] ?? 'Unreal File Catalog', $config);

    if ($page === 'home') {
        $games = allq($db, 'SELECT g.*, COUNT(f.id) file_count, COALESCE(SUM(f.file_size),0) total_size FROM ue_games g LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id ORDER BY g.name');
        echo '<div class="card"><h1>Unreal Games</h1><p class="muted">Browse verified Unreal packages, dependencies, imports, exports and MD5 hashes.</p></div><div class="grid">';
        foreach ($games as $game) {
            echo '<a class="stat" href="' . h(u(['page' => 'game', 'id' => $game['id']])) . '"><h2>' . h($game['name']) . '</h2><p>' . h($game['engine_key']) . '</p><p>' . (int)$game['file_count'] . ' files / ' . h(bytes_fmt((int)$game['total_size'])) . '</p></a>';
        }
        echo '</div>';
    } elseif ($page === 'game') {
        $gameId = (int)($_GET['id'] ?? 0);
        $game = one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) { throw new RuntimeException('Game not found'); }
        echo '<div class="card"><h1>' . h($game['name']) . '</h1><p class="muted">' . h($game['description']) . '</p></div>';
        $files = allq($db, "SELECT f.*, SUM(d.status='resolved') resolved_count, SUM(d.status='missing') missing_count, SUM(d.status='package_only') package_only_count, SUM(d.status='common') common_count FROM ue_files f LEFT JOIN ue_dependencies d ON d.file_id=f.id WHERE f.game_id=? GROUP BY f.id ORDER BY f.package_name,f.original_name", [$gameId]);
        echo '<div class="card"><h2>Files</h2><div class="scroll"><table><tr><th>Package</th><th>File</th><th>MD5</th><th>Size</th><th>Dependencies</th><th>Actions</th></tr>';
        foreach ($files as $file) {
            $deps = '';
            foreach (['resolved','missing','package_only','common'] as $key) {
                $count = (int)($file[$key . '_count'] ?? 0);
                if ($count) { $deps .= '<span class="dep ' . $key . '">' . $key . ': ' . $count . '</span>'; }
            }
            $deps = $deps ?: '<span class="muted">none</span>';
            echo '<tr><td class="mono">' . h($file['package_name']) . '</td><td>' . h($file['original_name']) . '<br><span class="muted small">GUID ' . h($file['package_guid']) . '</span></td><td class="mono small">' . h($file['md5']) . '</td><td>' . h(bytes_fmt((int)$file['file_size'])) . '</td><td>' . $deps . '</td><td><a href="' . h(u(['page' => 'file', 'id' => $file['id']])) . '">details</a> | <a href="' . h(u(['page' => 'examine', 'id' => $file['id']])) . '">examine</a> | <a href="' . h(u(['page' => 'download', 'id' => $file['id']])) . '">download</a></td></tr>';
        }
        echo '</table></div></div>';
        if (admin()) {
            echo '<div class="card"><h2>Upload files</h2><form method="post" enctype="multipart/form-data" action="' . h(u(['page' => 'upload'])) . '"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="game_id" value="' . $gameId . '"><p class="muted">Max per file: ' . h(bytes_fmt((int)$config['max_upload_bytes'])) . '. Failed scans move to the admin-only unverified folder.</p><input type="file" name="files[]" multiple required> <button>Upload and scan</button></form></div>';
        }
    } elseif ($page === 'file') {
        $id = (int)($_GET['id'] ?? 0);
        $file = one($db, 'SELECT f.*, g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
        if (!$file) { throw new RuntimeException('File not found'); }
        echo '<div class="card"><h1>' . h($file['package_name']) . '</h1><p>' . h($file['original_name']) . ' / ' . h($file['game_name']) . '</p><p><a class="button" href="' . h(u(['page' => 'download', 'id' => $id])) . '">Download</a> <a class="button" href="' . h(u(['page' => 'examine', 'id' => $id])) . '">Examine full parse</a></p><div class="grid"><div class="stat">MD5<br><span class="mono small">' . h($file['md5']) . '</span></div><div class="stat">SHA1<br><span class="mono small">' . h($file['sha1']) . '</span></div><div class="stat">GUID<br><span class="mono small">' . h($file['package_guid']) . '</span></div><div class="stat">Tables<br>' . (int)$file['name_count'] . ' names / ' . (int)$file['import_count'] . ' imports / ' . (int)$file['export_count'] . ' exports</div></div></div>';
        if (!empty($file['scan_notes'])) { echo '<div class="card"><h2>Scan notes</h2><pre class="mono">' . h($file['scan_notes']) . '</pre></div>'; }
        $deps = allq($db, 'SELECT d.*, rf.original_name resolved_file FROM ue_dependencies d LEFT JOIN ue_files rf ON rf.id=d.resolved_file_id WHERE d.file_id=? ORDER BY FIELD(d.status,"missing","package_only","resolved","common"), d.required_package, d.required_object_path', [$id]);
        echo '<div class="card"><h2>Dependencies</h2><table><tr><th>Status</th><th>Required object</th><th>Resolved by</th></tr>';
        foreach ($deps as $dep) {
            echo '<tr><td><span class="dep ' . h($dep['status']) . '">' . h($dep['status']) . '</span></td><td class="mono path">' . h($dep['required_object_path']) . '</td><td>' . ($dep['resolved_file_id'] ? '<a href="' . h(u(['page' => 'file', 'id' => $dep['resolved_file_id']])) . '">' . h($dep['resolved_file']) . '</a>' : '<span class="muted">not resolved</span>') . '</td></tr>';
        }
        echo '</table></div>';
        if (admin()) {
            echo '<div class="card"><h2>Admin</h2><form method="post" action="' . h(u(['page' => 'save_file'])) . '"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . $id . '">Package name <input name="package_name" value="' . h($file['package_name']) . '"> <button>Save and rebuild links</button></form><form method="post" action="' . h(u(['page' => 'delete_file'])) . '" onsubmit="return confirm(\'Delete this file and DB rows?\')"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . $id . '"><button class="danger">Delete file</button></form></div>';
        }
    } elseif ($page === 'examine') {
        $id = (int)($_GET['id'] ?? 0);
        $file = one($db, 'SELECT f.*, g.engine_key FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
        if (!$file) { throw new RuntimeException('File not found'); }
        $readerClass = load_reader_class($config, $file['engine_key']);
        $pkg = new $readerClass(__DIR__ . '/' . $file['relative_path']);
        echo '<div class="card"><h1>Examine: ' . h($file['original_name']) . '</h1><p><a href="' . h(u(['page' => 'file', 'id' => $id])) . '">Back</a></p></div>';
        foreach (['Header' => $pkg->getHeader(), 'Names' => $pkg->getNames(), 'Imports' => $pkg->getImports(), 'Exports' => $pkg->getExports()] as $label => $data) {
            echo '<div class="card"><h2>' . h($label) . '</h2><div class="scroll"><pre class="mono">' . h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></div></div>';
        }
    } elseif ($page === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        echo '<div class="card"><h1>Search</h1><form><input type="hidden" name="page" value="search"><input name="q" value="' . h($q) . '" placeholder="MD5, SHA1, GUID, package, import/export object, file name" style="min-width:420px"> <button>Search</button></form></div>';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $rows = allq($db, 'SELECT DISTINCT f.* FROM ue_files f LEFT JOIN ue_imports i ON i.file_id=f.id LEFT JOIN ue_exports e ON e.file_id=f.id WHERE f.md5=? OR f.sha1=? OR f.package_guid LIKE ? OR f.package_name LIKE ? OR f.original_name LIKE ? OR i.full_path LIKE ? OR e.full_path LIKE ? ORDER BY f.package_name LIMIT 200', [$q, $q, $like, $like, $like, $like, $like]);
            echo '<div class="card"><h2>Results</h2><table><tr><th>Package</th><th>File</th><th>MD5</th><th>Open</th></tr>';
            foreach ($rows as $row) { echo '<tr><td class="mono">' . h($row['package_name']) . '</td><td>' . h($row['original_name']) . '</td><td class="mono small">' . h($row['md5']) . '</td><td><a href="' . h(u(['page' => 'file', 'id' => $row['id']])) . '">details</a></td></tr>'; }
            echo '</table></div>';
        }
    } elseif ($page === 'login') {
        $count = (int)(one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        echo '<div class="card"><h1>' . ($count ? 'Admin Login' : 'Create first admin user') . '</h1><form method="post"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><p><input name="username" required placeholder="Username"></p><p><input type="password" name="password" required placeholder="Password"></p><button>' . ($count ? 'Login' : 'Create admin') . '</button></form></div>';
    } elseif ($page === 'admin') {
        need_admin();
        $games = allq($db, 'SELECT * FROM ue_games ORDER BY name');
        echo '<div class="card"><h1>Admin</h1></div><div class="card"><h2>Games</h2><table><tr><th>Name</th><th>Slug</th><th>Engine</th><th>Open</th></tr>';
        foreach ($games as $game) { echo '<tr><td>' . h($game['name']) . '</td><td>' . h($game['slug']) . '</td><td>' . h($game['engine_key']) . '</td><td><a href="' . h(u(['page' => 'game', 'id' => $game['id']])) . '">open</a></td></tr>'; }
        echo '</table></div><div class="card"><h2>Add game</h2><form method="post" action="' . h(u(['page' => 'save_game'])) . '"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input name="name" required placeholder="Game name"> <input name="slug" required placeholder="slug"> <select name="engine_key">';
        foreach ($config['engine_readers'] as $key => $reader) { echo '<option value="' . h($key) . '">' . h($key . ' - ' . $reader['label']) . '</option>'; }
        echo '</select><p><textarea name="description" rows="3" style="width:100%" placeholder="Description"></textarea></p><button>Save game</button></form></div>';
    } else {
        throw new RuntimeException('Unknown page');
    }

    page_foot();
} catch (Throwable $e) {
    if (!headers_sent()) { page_head('Catalog Error', $config ?? []); }
    echo '<div class="msg err"><strong>Error:</strong> ' . h($e->getMessage()) . '</div>';
    echo '<div class="card"><h2>Setup checklist</h2><ol><li>Copy <code>catalog/config.example.php</code> to <code>catalog/config.php</code>.</li><li>Edit the database settings.</li><li>Import <code>catalog/install.sql</code>.</li><li>Make <code>catalog/storage/</code> writable by PHP.</li></ol></div>';
    page_foot();
}
