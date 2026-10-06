#!/usr/bin/env php
<?php
declare(strict_types=1);

fwrite(STDERR, "This command is disabled because the v510 source-policy model was invalid. Do not relabel rows with this command; use the corrected v511 migration tooling.\n");
exit(2);
