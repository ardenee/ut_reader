<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();

header('Location: dashboard.php');
exit;
