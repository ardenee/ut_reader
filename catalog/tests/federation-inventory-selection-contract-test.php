<?php
declare(strict_types=1);

function federation_inventory_selection_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$peerInventory = file_get_contents($root . '/federation/peer-inventory.php');
$requestGenerate = file_get_contents($root . '/federation/request-generate.php');
$requestSubmit = file_get_contents($root . '/api/federation/request-submit.php');

foreach ([$peerInventory, $requestGenerate, $requestSubmit] as $content) {
    federation_inventory_selection_expect(is_string($content), 'A required federation inventory/request file is missing.');
}

federation_inventory_selection_expect(str_contains($peerInventory, 'const PI_PAGE_SIZE = 950;'), 'Peer inventory is not capped below max_input_vars.');
federation_inventory_selection_expect(str_contains($peerInventory, 'data-check-all="parent-files"'), 'Peer inventory lacks a check-all control.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Needed by files'), 'Peer inventory lacks the game-specific dependency count.');
federation_inventory_selection_expect(str_contains($peerInventory, 'COUNT(DISTINCT needer.id)'), 'Peer inventory does not count distinct requiring files.');
federation_inventory_selection_expect(str_contains($peerInventory, 'GUID / MD5 / SHA1'), 'Peer inventory identity fields are not combined.');
federation_inventory_selection_expect(!str_contains($peerInventory, '<th>Last seen</th>'), 'Peer inventory still displays the Last seen column.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Files the parent has that the child needs'), 'Peer inventory lacks the Child tab data.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Parent downloads'), 'Peer inventory lacks the parent download-history tab.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Child downloads'), 'Peer inventory lacks the child download-history tab.');

federation_inventory_selection_expect(str_contains($requestGenerate, 'const REQGEN_PAGE_SIZE = 950;'), 'Request generator page size is not 950.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'name="item_keys[]"'), 'Request generator does not submit selected package keys.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'data-check-all="request-packages" checked'), 'Request generator does not select all packages by default.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'COUNT(DISTINCT d.file_id) use_count'), 'Request generator does not count distinct requiring files.');
federation_inventory_selection_expect(!str_contains($requestGenerate, 'array_slice($items, 0, 300)'), 'Request generator still has the obsolete 300-row display limit.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'federation_peer_stored_signing_secret'), 'Request generator bypasses encrypted peer-secret handling.');

federation_inventory_selection_expect(str_contains($requestSubmit, 'count($items) > 950'), 'Parent request endpoint does not enforce the 950-package limit.');
federation_inventory_selection_expect(str_contains($requestSubmit, 'request_submit_package_match'), 'Parent request endpoint lacks contextual package matching.');
federation_inventory_selection_expect(str_contains($requestSubmit, "\$item['game_name']"), 'Parent request endpoint ignores child game context.');
federation_inventory_selection_expect(str_contains($requestSubmit, "\$item['engine_key']"), 'Parent request endpoint ignores child engine context.');

echo "Federation inventory selection contract tests passed.\n";
