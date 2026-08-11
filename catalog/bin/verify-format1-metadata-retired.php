#!/usr/bin/env php
<?php
/**
 * Purpose: Proves the retired format-1 verified metadata reader/converter cannot re-enter runtime code.
 * Role: Static repository gate with an optional live database format-version check.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$withDatabase = in_array('--database', array_slice($argv, 1), true);
$checks = [];
$failures = [];

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$withoutComments = static function (string $source): string {
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
};

$retiredPaths = [
    'src/Infrastructure/Metadata/CompressedFileMetadataReader.php',
    'src/Infrastructure/Metadata/BatchedCompressedFileMetadataConverter.php',
    'src/Infrastructure/Metadata/CompressedFileMetadataConverter.php',
    'bin/convert-file-metadata.php',
];
foreach ($retiredPaths as $relative) {
    $record(
        'retired_file_absent:' . $relative,
        !is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        $relative
    );
}

$currentPaths = [
    'src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotLoader.php',
    'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
];
foreach ($currentPaths as $relative) {
    $record(
        'current_file_present:' . $relative,
        is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        $relative
    );
}

$retiredClassNames = [
    'CompressedFileMetadataReader',
    'BatchedCompressedFileMetadataConverter',
];
$retiredStorageSuffix = '.uedb.json.gz';
$identifierTokenTypes = [T_STRING];
foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
    if (defined($constant)) {
        $identifierTokenTypes[] = constant($constant);
    }
}
$stringTokenTypes = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

$references = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$self = realpath(__FILE__) ?: __FILE__;
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $real = realpath($path) ?: $path;
    if ($real === $self) {
        continue;
    }
    $normalized = str_replace('\\', '/', $real);
    if (str_contains($normalized, '/migrations/')) {
        // Applied migrations are immutable historical records and are not runtime implementation classes.
        continue;
    }
    $source = @file_get_contents($real);
    if (!is_string($source)) {
        $references[$normalized][] = 'unreadable';
        continue;
    }

    // Verification contracts legitimately contain retired class names inside
    // string literals so they can assert that production files do not contain
    // them. Scan PHP identifier tokens everywhere, but ignore string-literal
    // references inside bin/verify-*.php to avoid treating assertions as callers.
    $isVerificationScript = preg_match('#/bin/verify-[^/]+\.php$#i', $normalized) === 1;
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }
        [$type, $text] = $token;
        if (in_array($type, [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (in_array($type, $identifierTokenTypes, true)) {
            foreach ($retiredClassNames as $className) {
                if (str_contains($text, $className)) {
                    $references[$normalized][] = $className;
                }
            }
            continue;
        }
        if (!$isVerificationScript && in_array($type, $stringTokenTypes, true)) {
            foreach ($retiredClassNames as $className) {
                if (str_contains($text, $className)) {
                    $references[$normalized][] = $className . ' (dynamic string reference)';
                }
            }
            if (str_contains($text, $retiredStorageSuffix)) {
                $references[$normalized][] = $retiredStorageSuffix;
            }
        }
    }
}
foreach ($references as $path => $tokens) {
    $references[$path] = array_values(array_unique($tokens));
}
$record(
    'no_format1_runtime_references',
    $references === [],
    $references === [] ? 'no executable format-1 verified metadata references' : json_encode($references, JSON_UNESCAPED_SLASHES)
);

$writerPath = $root . '/src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php';
$writerSource = @file_get_contents($writerPath);
$writerExecutable = is_string($writerSource) ? $withoutComments($writerSource) : '';
$record(
    'projection_writer_has_no_implicit_format1_entry_point',
    $writerExecutable !== ''
        && !str_contains($writerExecutable, 'public function write(')
        && !str_contains($writerExecutable, 'BatchedCompressedFileMetadataConverter')
        && str_contains($writerExecutable, 'public function writeVersioned('),
    'current callers must supply the authoritative metadata format version and codec explicitly'
);

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $db = $application->db;

        $nonFormat2 = (int)$db->query(
            'SELECT COUNT(*) FROM ue_file_metadata WHERE format_version<>2'
        )->fetchColumn();
        $record(
            'database_has_only_format2_metadata',
            $nonFormat2 === 0,
            'non_format2_rows=' . $nonFormat2
        );

        $verifiedWithoutFormat2 = (int)$db->query(
            'SELECT COUNT(*) FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<>2)'
        )->fetchColumn();
        $record(
            'verified_files_have_format2_metadata',
            $verifiedWithoutFormat2 === 0,
            'verified_without_format2=' . $verifiedWithoutFormat2
        );
    } catch (Throwable $error) {
        $record('database_format2_check', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'database_checked' => $withDatabase,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
