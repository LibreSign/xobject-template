<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        NewInInitializerRector::class,
    ])
    ->withPhpSets();
