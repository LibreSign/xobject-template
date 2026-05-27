<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/src/Layout/LinearLayoutEngine.php',
        __DIR__ . '/src/XObjectTemplateCompiler.php',
    ])
    ->withPhpSets();
