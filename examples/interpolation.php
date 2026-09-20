<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__, 3);
$outputDir = $projectRoot . '/build/examples';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$result = (new XObjectTemplateCompiler())->compile(new CompileRequest(
    html: '<div style="font-size:10">Signed by {{ name }}</div>'
        . '<div style="font-size:9">Role: {{ role }}</div>'
        . '<div style="font-size:9">Missing: {{ missing }}</div>',
    width: 260.0,
    height: 90.0,
    context: [
        'name' => 'Alice <Admin>',
        'role' => 'Approver',
    ],
));

$outputFile = $outputDir . '/interpolation-result.json';
file_put_contents($outputFile, json_encode([
    'bbox' => $result->bbox,
    'content_stream' => $result->contentStream,
    'resources' => $result->resources,
    'metadata' => $result->metadata,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

return [
    'example' => 'interpolation',
    'generated_files' => [$outputFile],
    'content_stream' => $result->contentStream,
    'resources' => $result->resources,
    'bbox' => $result->bbox,
];
