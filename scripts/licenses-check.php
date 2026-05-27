<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

$lockFile = __DIR__ . '/../composer.lock';
if (!is_file($lockFile)) {
    fwrite(STDOUT, "composer.lock not found; skipping strict license scan for bootstrap phase.\n");
    exit(0);
}

$data = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
$packages = array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);

$denyPatterns = ['GPL-2.0-only', 'proprietary'];
$violations = [];

foreach ($packages as $package) {
    $licenses = $package['license'] ?? [];
    $name = $package['name'] ?? 'unknown';
    foreach ($licenses as $license) {
        foreach ($denyPatterns as $deny) {
            if (stripos((string) $license, $deny) !== false) {
                $violations[] = sprintf('%s has incompatible license %s', $name, $license);
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "License policy check passed.\n");
