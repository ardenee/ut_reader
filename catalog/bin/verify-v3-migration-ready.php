#!/usr/bin/env php
<?php
/** Aggregate source gate for the v3-only metadata migration. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$scripts = [
    'verify-v3-only-runtime.php',
    'verify-v3-package-object-coverage-contract.php',
    'verify-v3-package-superset-contract.php',
    'verify-unreal-dependency-identity-contract.php',
    'verify-compact-metadata-repair-contract.php',
    'verify-v3-cutover-contract.php',
    'verify-compact-only-metadata-runtime.php',
];
$results=[];$ok=true;
foreach($scripts as $script){
    $path=$root.'/bin/'.$script;
    $proc=proc_open([PHP_BINARY,$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $stdout='';$stderr='';$exit=1;
    if(is_resource($proc)){
        $stdout=(string)stream_get_contents($pipes[1]);
        $stderr=(string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        $exit=proc_close($proc);
    }
    $passed=$exit===0;$ok=$ok&&$passed;
    $results[]=[
        'script'=>$script,
        'ok'=>$passed,
        'exit_code'=>$exit,
        'stdout'=>$passed ? '' : trim($stdout),
        'stderr'=>$passed ? '' : trim($stderr),
    ];
}
echo json_encode(['ok'=>$ok,'checks'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
