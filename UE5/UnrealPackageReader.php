<?php
declare(strict_types=1);

require_once __DIR__ . '/../UE4/UnrealPackageReader.php';

if (!class_exists('UnrealPackageReader5', false)) {
    class_alias('UnrealPackageReader4', 'UnrealPackageReader5');
}
