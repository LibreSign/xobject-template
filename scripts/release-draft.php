<?php

declare(strict_types=1);

$tag = getenv('RELEASE_TAG') ?: 'v0.1.0-draft';
$notes = "Draft release generated from conventional workflow for {$tag}.";

fwrite(STDOUT, $notes . PHP_EOL);
