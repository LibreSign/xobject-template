<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Integration\XObjectPlacementCalculator;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__, 3);
$outputDir = $projectRoot . '/build/examples';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$result = (new XObjectTemplateCompiler())->compile(new CompileRequest(
    html: '<div style="font-size:10">Placement baseline</div>',
    width: 240.0,
    height: 84.0,
));

$calculator = new XObjectPlacementCalculator();
$fromWidth = $calculator->fromWidth($result, 175.0, 36.0, 72.0);
$fromHeight = $calculator->fromHeight($result, 61.25, 36.0, 72.0);
$fromScale = $calculator->fromScale($result, 0.729167, 36.0, 72.0);

$outputFile = $outputDir . '/placement-result.json';
file_put_contents($outputFile, json_encode([
    'from_width_command' => $fromWidth->toPdfCommand('Fm0'),
    'from_height_command' => $fromHeight->toPdfCommand('Fm0'),
    'from_scale_command' => $fromScale->toPdfCommand('Fm0'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

return [
    'example' => 'placement',
    'generated_files' => [$outputFile],
    'content_stream' => $result->contentStream,
    'resources' => $result->resources,
    'bbox' => $result->bbox,
];
