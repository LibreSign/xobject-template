<?php

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

$iterations = (int) ($_ENV['BENCH_ITERATIONS'] ?? 200);
$maxAvgMs = (float) ($_ENV['BENCH_MAX_AVG_MS'] ?? 3.5);
$maxMemKb = (int) ($_ENV['BENCH_MAX_MEM_KB'] ?? 1536);

$compiler = new XObjectTemplateCompiler();
$html = '<div style="font-size:10;color:#000">Signed by Demo User</div><p style="font-size:9">Document approved</p>';

$start = hrtime(true);
$peakStart = memory_get_peak_usage(true);

for ($i = 0; $i < $iterations; ++$i) {
    $compiler->compile(new CompileRequest(html: $html, width: 240, height: 84));
}

$elapsedMs = (hrtime(true) - $start) / 1_000_000;
$avgMs = $elapsedMs / max($iterations, 1);
$peakKb = (int) ((memory_get_peak_usage(true) - $peakStart) / 1024);

fwrite(STDOUT, sprintf("iterations=%d avg_ms=%.4f peak_kb=%d\n", $iterations, $avgMs, $peakKb));

if ($avgMs > $maxAvgMs || $peakKb > $maxMemKb) {
    fwrite(STDERR, sprintf("Performance threshold exceeded: avg_ms<=%.2f and peak_kb<=%d required.\n", $maxAvgMs, $maxMemKb));
    exit(1);
}
