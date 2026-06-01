<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__, 2);
$assetDir = $projectRoot . '/docs/examples/assets';
$outputDir = $projectRoot . '/build/examples';
$outputAssetDir = $outputDir . '/assets';

if (!is_dir($outputAssetDir)) {
    mkdir($outputAssetDir, 0777, true);
}

$pngPath = $outputAssetDir . '/tiny.png';
$jpegPath = $outputAssetDir . '/tiny.jpg';

$pngBytes = base64_decode((string) file_get_contents($assetDir . '/tiny-png.base64'), true);
$jpegBytes = base64_decode((string) file_get_contents($assetDir . '/tiny-jpeg.base64'), true);

if (!is_string($pngBytes) || !is_string($jpegBytes)) {
    throw new RuntimeException('Failed to decode example image assets.');
}

file_put_contents($pngPath, $pngBytes);
file_put_contents($jpegPath, $jpegBytes);

$html = '<div style="display:flex;flex-direction:row;align-items:center;gap:8;width:220;height:52">'
    . '<img src="' . htmlspecialchars($pngPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="width:24px;height:24px" />'
    . '<img src="' . htmlspecialchars($jpegPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="width:24px;height:24px" />'
    . '<span style="font-size:10">PNG + JPEG</span>'
    . '</div>';

$result = (new XObjectTemplateCompiler())->compile(new CompileRequest(
    html: $html,
    width: 260.0,
    height: 90.0,
));

$pdfFile = $outputDir . '/images-preview.pdf';
$pdfBytes = (new SinglePagePdfExporter())->export($result);
file_put_contents($pdfFile, $pdfBytes);

$jsonFile = $outputDir . '/images-result.json';
file_put_contents($jsonFile, json_encode([
    'bbox' => $result->bbox,
    'resources' => $result->resources,
    'metadata' => $result->metadata,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

return [
    'example' => 'images',
    'generated_files' => [$pngPath, $jpegPath, $pdfFile, $jsonFile],
    'content_stream' => $result->contentStream,
    'resources' => $result->resources,
    'bbox' => $result->bbox,
    'pdf_file' => $pdfFile,
];
