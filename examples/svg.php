<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$outputDir = $projectRoot . '/build/examples';
$svgPath = $projectRoot . '/examples/assets/sample.svg';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$html = '<div style="display:flex;align-items:center;gap:8;width:280;height:80">'
    . '<img src="' . htmlspecialchars($svgPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="width:96px;height:36px" />'
    . '<span style="font-size:10;color:#222222">SVG sample</span>'
    . '</div>';

$result = (new XObjectTemplateCompiler())->compile(new CompileRequest(
    html: $html,
    width: 280.0,
    height: 100.0,
));

$pdfFile = $outputDir . '/svg-preview.pdf';
$pdfBytes = (new SinglePagePdfExporter())->export($result);
file_put_contents($pdfFile, $pdfBytes);

$jsonFile = $outputDir . '/svg-result.json';
file_put_contents($jsonFile, json_encode([
    'bbox' => $result->bbox,
    'resources' => $result->resources,
    'metadata' => $result->metadata,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

return [
    'example' => 'svg',
    'generated_files' => [$pdfFile, $jsonFile],
    'content_stream' => $result->contentStream,
    'resources' => $result->resources,
    'bbox' => $result->bbox,
    'pdf_file' => $pdfFile,
];
