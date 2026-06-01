<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Pdf\SinglePagePdfExporter;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__, 3);
$outputDir = $projectRoot . '/build/examples';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$result = (new XObjectTemplateCompiler())->compile(new CompileRequest(
    html: '<div style="font-size:14;font-weight:700;color:#222222">Preview me</div>'
        . '<div style="font-size:10;color:#666666;margin:6 0 0 0">Visible appearance test</div>',
    width: 260.0,
    height: 90.0,
));

$pdfBytes = (new SinglePagePdfExporter())->export($result);
$pdfFile = $outputDir . '/preview.pdf';
file_put_contents($pdfFile, $pdfBytes);

return [
    'example' => 'preview-pdf',
    'generated_files' => [$pdfFile],
    'content_stream' => $result->contentStream,
    'resources' => $result->resources,
    'bbox' => $result->bbox,
    'pdf_file' => $pdfFile,
];
