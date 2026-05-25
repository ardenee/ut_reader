<?php
/**
 * Compatibility entrypoint for UT Reader package parsing.
 *
 * The old experimental parser was replaced by TUnrealPackage2.php so existing
 * scripts that require TUnrealPackage.php continue to work without changes.
 */
require_once __DIR__ . '/TUnrealPackage2.php';
