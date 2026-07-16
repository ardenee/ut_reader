<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$query = http_build_query([
    'id' => $id,
    'format' => 'dependency_zip',
    'dependencies' => 1,
]);
header('Location: download-package.php?' . $query, true, 302);
exit;
