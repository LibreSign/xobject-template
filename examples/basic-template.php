<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$outputDir = $projectRoot . '/build/examples';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$result = (new XObjectTemplateCompiler())->compile(new CompileRequest(
    html: '<div style="font-size:12;color:#111111">Signed by {{ name }}</div>'
        . '<div style="font-size:10;color:#555555">Role: {{ role }}</div>',
    width: 240.0,
    height: 84.0,
    context: [
        'name' => 'Alice',
        'role' => 'Approver',
    ],
));

$outputFile = $outputDir . '/basic-template-result.json';
file_put_contents($outputFile, json_encode([
    'content_stream_length' => strlen($result->contentStream),
    'resources' => $result->resources,
    'bbox' => $result->bbox,
    'metadata' => $result->metadata,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

return [
    'example' => 'basic-template',
    'generated_files' => [$outputFile],
    'content_stream' => $result->contentStream,
    'resources' => $result->resources,
    'bbox' => $result->bbox,
];
