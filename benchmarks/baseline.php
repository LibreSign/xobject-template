<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

$root = dirname(__DIR__);
$baselinePath = $root . '/.github/.performance/baseline.json';
$outputPath = $root . '/build/benchmark-output.txt';

$command = $argv[1] ?? null;
if ($command === null || !in_array($command, ['update', 'check', 'check-stale'], true)) {
    fwrite(STDERR, "Usage: php benchmarks/baseline.php <update|check|check-stale>\n");
    exit(1);
}

if (!file_exists($outputPath)) {
    fwrite(STDERR, "Benchmark output file not found: {$outputPath}\n");
    exit(1);
}

$output = file_get_contents($outputPath);
if ($output === false) {
    fwrite(STDERR, "Unable to read benchmark output file.\n");
    exit(1);
}

$patterns = [
    'LibreSign\\XObjectTemplate\\Benchmarks\\CompilerBench::benchSimpleHtml' => '/benchSimpleHtml.*?Mo([0-9]+(?:\\.[0-9]+)?)(μs|us|ms)/',
    'LibreSign\\XObjectTemplate\\Benchmarks\\CompilerBench::benchComplexHtml' => '/benchComplexHtml.*?Mo([0-9]+(?:\\.[0-9]+)?)(μs|us|ms)/',
];

$current = [];
foreach ($patterns as $name => $pattern) {
    if (!preg_match($pattern, $output, $matches)) {
        fwrite(STDERR, "Metric not found in benchmark output for {$name}.\n");
        exit(1);
    }

    $value = (float) $matches[1];
    $unit = $matches[2];
    $currentMs = $unit === 'ms' ? $value : ($value / 1000.0);

    $current[$name] = $currentMs;
}

if ($command === 'update') {
    $baseline = [
        'version' => '1.0.0',
        'created_at' => gmdate('Y-m-d'),
        'allowed_regression_pct' => 25.0,
        'stale_threshold_pct' => 35.0,
        'benchmarks' => [
            'LibreSign\\XObjectTemplate\\Benchmarks\\CompilerBench::benchSimpleHtml' => [
                'mean' => round($current['LibreSign\\XObjectTemplate\\Benchmarks\\CompilerBench::benchSimpleHtml'], 6),
                'memory_real' => 768,
            ],
            'LibreSign\\XObjectTemplate\\Benchmarks\\CompilerBench::benchComplexHtml' => [
                'mean' => round($current['LibreSign\\XObjectTemplate\\Benchmarks\\CompilerBench::benchComplexHtml'], 6),
                'memory_real' => 1024,
            ],
        ],
    ];

    $encoded = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fwrite(STDERR, "Unable to encode baseline JSON.\n");
        exit(1);
    }

    $dir = dirname($baselinePath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create baseline directory.\n");
        exit(1);
    }

    if (file_put_contents($baselinePath, $encoded . "\n") === false) {
        fwrite(STDERR, "Unable to write baseline file.\n");
        exit(1);
    }

    fwrite(STDOUT, "Baseline updated: {$baselinePath}\n");
    exit(0);
}

if (!file_exists($baselinePath)) {
    fwrite(STDERR, "Baseline file not found: {$baselinePath}\n");
    exit(1);
}

$baselineJson = file_get_contents($baselinePath);
if ($baselineJson === false) {
    fwrite(STDERR, "Unable to read baseline file.\n");
    exit(1);
}

$baseline = json_decode($baselineJson, true);
if (!is_array($baseline)) {
    fwrite(STDERR, "Invalid baseline JSON format.\n");
    exit(1);
}

$baselineBenchmarks = $baseline['benchmarks'] ?? [];
if (!is_array($baselineBenchmarks)) {
    fwrite(STDERR, "Invalid baseline benchmark section.\n");
    exit(1);
}

$allowedRegressionPct = (float) ($baseline['allowed_regression_pct'] ?? 25.0);
$staleThresholdPct = (float) ($baseline['stale_threshold_pct'] ?? 35.0);

$failures = [];
foreach ($current as $name => $currentMs) {
    if (!isset($baselineBenchmarks[$name]['mean'])) {
        $failures[] = "{$name}: missing baseline entry";
        continue;
    }

    $baselineMs = (float) $baselineBenchmarks[$name]['mean'];
    if ($baselineMs <= 0) {
        $failures[] = "{$name}: invalid baseline mean {$baselineMs}";
        continue;
    }

    $deltaPct = (($currentMs - $baselineMs) / $baselineMs) * 100.0;
    $limitPct = $command === 'check' ? $allowedRegressionPct : $staleThresholdPct;

    fwrite(
        STDOUT,
        sprintf(
            "%s: current=%.6fms baseline=%.6fms delta=%+.2f%% limit=%s%.2f%%\n",
            $name,
            $currentMs,
            $baselineMs,
            $deltaPct,
            $command === 'check' ? '+' : '±',
            $limitPct,
        ),
    );

    if ($command === 'check' && $deltaPct > $allowedRegressionPct) {
        $failures[] = sprintf(
            '%s: regression detected (%+.2f%% > +%.2f%%)',
            $name,
            $deltaPct,
            $allowedRegressionPct,
        );
    }

    if ($command === 'check-stale' && abs($deltaPct) > $staleThresholdPct) {
        $failures[] = sprintf(
            '%s: baseline appears stale (%+.2f%% exceeds ±%.2f%%). Run composer benchmark:baseline:update',
            $name,
            $deltaPct,
            $staleThresholdPct,
        );
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nBaseline validation failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "\nBaseline validation passed.\n");
