#!/usr/bin/env php
<?php
declare(strict_types=1);

fwrite(
    STDERR,
    "This command is disabled. The v510 remediation model was invalid because it omitted the real "
    . "EUnrealEngineObjectUE4Version member VAR_UE4_ARRAY_PROPERTY_INNER_TAGS. "
    . "Use catalog/bin/repair-ut4-v511-pass1.php to repair only rows written with the bad v510 policy.\n"
);
exit(2);
