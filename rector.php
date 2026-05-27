<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        'Rector\\Php84\\Rector\\Class_\\NewInInitializerRector',
    ])
    ->withPhpSets();
