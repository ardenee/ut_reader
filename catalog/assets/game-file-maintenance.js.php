<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, private');

if (!catalog_support_is_admin()) {
    exit;
}
?>
(function () {
    'use strict';
    var table = document.getElementById('game-files-table');
    if (!table || !table.tHead || !table.tBodies.length) return;

    var header = document.createElement('th');
    header.scope = 'col';
    header.textContent = 'Admin';
    table.tHead.rows[0].appendChild(header);

    Array.from(table.tBodies[0].rows).forEach(function (row) {
        var cell = document.createElement('td');
        cell.textContent = '↻';
        row.appendChild(cell);
    });
})();
