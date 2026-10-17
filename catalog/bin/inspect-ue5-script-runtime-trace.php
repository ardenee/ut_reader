<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ScriptRuntimeTraceInspector;

if (PHP_SAPI !== 'cli') exit(2);
$log=null;
foreach (array_slice($argv,1) as $arg) {
    if (str_starts_with($arg,'--log=') && $log===null) {
        $log=substr($arg,strlen('--log='));
    } else {
        fwrite(STDERR, "Usage: php inspect-ue5-script-runtime-trace.php --log=PATH\n");
        exit(2);
    }
}
if (!is_string($log) || $log==='') {
    fwrite(STDERR, "Usage: php inspect-ue5-script-runtime-trace.php --log=PATH\n");
    exit(2);
}
try {
    $result=Uedb5Ue5ScriptRuntimeTraceInspector::inspectFile($log);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $error) {
    // Never echo log lines or user-supplied object paths on failure.
    fwrite(STDERR,"Unable to inspect this bounded local trace: ".get_class($error)."\n");
    exit(2);
}
