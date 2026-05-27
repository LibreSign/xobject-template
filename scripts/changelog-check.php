<?php

declare(strict_types=1);

$changelog = __DIR__ . '/../CHANGELOG.md';
if (!is_file($changelog)) {
    fwrite(STDERR, "CHANGELOG.md not found\n");
    exit(1);
}

$content = (string) file_get_contents($changelog);
if (!str_contains($content, '## [Unreleased]')) {
    fwrite(STDERR, "Missing [Unreleased] section\n");
    exit(1);
}

fwrite(STDOUT, "Changelog format check passed.\n");
