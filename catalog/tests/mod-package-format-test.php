<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ModPackageBuilder.php';

$tmp = sys_get_temp_dir() . '/unrealdb-modpkg-test-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    throw new RuntimeException('Could not create test directory.');
}

try {
    $payload = $tmp . '/DM-Test.ut2';
    file_put_contents($payload, "UnrealDB package writer test\n");
    $size = filesize($payload);
    $plan = [
        'format' => MODPKG_FORMAT_UT2MOD,
        'root' => ['id' => 1, 'package_name' => 'DM-Test'],
        'game' => ['id' => 1, 'name' => 'Unreal Tournament 2003', 'slug' => 'ut2003', 'engine_key' => 'UE2', 'profile_name' => 'UT2003'],
        'files' => [[
            'id' => 1,
            'install_path' => 'Maps/DM-Test.ut2',
            'install_path_inferred' => false,
            'source_relative_path' => 'Maps/DM-Test.ut2',
            'package_name' => 'DM-Test',
            'original_name' => 'DM-Test.ut2',
            'md5' => md5_file($payload),
            'sha1' => sha1_file($payload),
            'package_guid' => 'TEST',
            'file_size' => $size,
            'storage_path' => $payload,
        ]],
        'file_count' => 1,
        'total_bytes' => $size,
        'blocked' => [],
        'missing' => [],
        'package_only' => [],
        'common' => [],
        'include_dependencies' => true,
        'transitive_dependencies' => true,
    ];
    $options = ['name' => 'DM-Test', 'version' => '1.0', 'author' => 'UnrealDB'];

    $umod = $tmp . '/DM-Test.ut2mod';
    $umodResult = modpkg_write_umod($umod, $plan, $options);
    if (!$umodResult['ok'] || !is_file($umod)) {
        throw new RuntimeException('UMOD test failed.');
    }

    $plan['format'] = MODPKG_FORMAT_UT4_PAK;
    $plan['game'] = ['id' => 2, 'name' => 'Unreal Tournament', 'slug' => 'ut4', 'engine_key' => 'UE4', 'profile_name' => 'UT4'];
    $plan['files'][0]['install_path'] = 'UnrealDB/DM-Test/DM-Test.uasset';
    $pak = $tmp . '/DM-Test.pak';
    $pakResult = modpkg_write_pak($pak, $plan, $options, ['ut4_pak_version' => 3, 'ut4_mount_point' => '../../../UnrealTournament/Content/']);
    if (!$pakResult['ok'] || !is_file($pak)) {
        throw new RuntimeException('PAK test failed.');
    }

    echo "UMOD entries: " . $umodResult['file_count'] . PHP_EOL;
    echo "PAK entries: " . $pakResult['file_count'] . PHP_EOL;
    echo "OK" . PHP_EOL;
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    @rmdir($tmp);
}
