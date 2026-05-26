<?php
declare(strict_types=1);

require_once __DIR__ . '/../UE4/UnrealPackageReader.php';

if (!class_exists('UnrealPackageReader4', false)) {
    throw new RuntimeException('UE4 reader loaded, but UnrealPackageReader4 was not defined.');
}

if (!class_exists('UnrealPackageReader', false)) {
    class_alias('UnrealPackageReader4', 'UnrealPackageReader');
}
