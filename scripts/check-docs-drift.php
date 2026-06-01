<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$errors = [];

$mkdocsPath = $projectRoot . '/mkdocs.yml';
$mkdocs = file_get_contents($mkdocsPath);
if (!is_string($mkdocs)) {
    fwrite(STDERR, "Failed to read mkdocs.yml\n");
    exit(1);
}

preg_match_all('/:\s*([A-Za-z0-9\-\/]+\.md)\s*$/m', $mkdocs, $navMatches);
$navPages = array_values(array_unique($navMatches[1] ?? []));
foreach ($navPages as $navPage) {
    $docPath = $projectRoot . '/docs/' . $navPage;
    if (!is_file($docPath)) {
        $errors[] = sprintf('mkdocs navigation page does not exist: docs/%s', $navPage);
    }
}

$docsFiles = [];
$docsDirectory = new RecursiveDirectoryIterator($projectRoot . '/docs');
$docsIterator = new RecursiveIteratorIterator($docsDirectory);
foreach ($docsIterator as $docEntry) {
    if (!$docEntry->isFile()) {
        continue;
    }

    if (strtolower($docEntry->getExtension()) !== 'md') {
        continue;
    }

    $docsFiles[] = $docEntry->getPathname();
}

$allDocContent = '';
foreach ($docsFiles as $docFile) {
    $content = file_get_contents($docFile);
    if (is_string($content)) {
        $allDocContent .= "\n" . $content;
    }
}

preg_match_all('/examples\/[a-z0-9\-]+\.php/', $allDocContent, $exampleMatches);
$referencedExamples = array_values(array_unique($exampleMatches[0] ?? []));
foreach ($referencedExamples as $exampleReference) {
    $examplePath = $projectRoot . '/' . $exampleReference;
    if (!is_file($examplePath)) {
        $errors[] = sprintf('Documented example does not exist: %s', $exampleReference);
    }
}

$examplesTestPath = $projectRoot . '/tests/Documentation/ExamplesTest.php';
$examplesTest = file_get_contents($examplesTestPath);
if (!is_string($examplesTest)) {
    $errors[] = 'Could not read tests/Documentation/ExamplesTest.php';
} else {
    $allExamples = glob($projectRoot . '/examples/*.php');
    if (!is_array($allExamples)) {
        $allExamples = [];
    }

    foreach ($allExamples as $exampleFile) {
        $base = basename($exampleFile);
        if (!str_contains($examplesTest, "'" . $base . "'")) {
            $errors[] = sprintf('Example not declared in ExamplesTest::documentedExamples(): %s', $base);
        }
    }
}

$subsetParserPath = $projectRoot . '/src/Html/SubsetHtmlParser.php';
$subsetParser = file_get_contents($subsetParserPath);
$supportedHtmlDoc = file_get_contents($projectRoot . '/docs/reference/supported-html-css.md');
if (is_string($subsetParser) && is_string($supportedHtmlDoc)) {
    if (preg_match('/private array \$allowedTags\s*=\s*\[(.*?)\];/s', $subsetParser, $allowedTagsMatch) === 1) {
        preg_match_all("/'([a-z]+)'\\s*=>\\s*true/", $allowedTagsMatch[1], $tagMatches);
        $tags = array_values(array_unique($tagMatches[1] ?? []));
        foreach ($tags as $tag) {
            if (!str_contains($supportedHtmlDoc, '<' . $tag . '>')) {
                $errors[] = sprintf('Supported HTML tag missing from docs/reference/supported-html-css.md: <%s>', $tag);
            }
        }
    }
}

$svgParserPath = $projectRoot . '/src/Pdf/Svg/SvgPathCommandParser.php';
$svgParser = file_get_contents($svgParserPath);
$supportedSvgDoc = file_get_contents($projectRoot . '/docs/reference/supported-svg.md');
if (is_string($svgParser) && is_string($supportedSvgDoc)) {
    preg_match_all("/'([A-Z])'\s*=>/", $svgParser, $cmdMatches);
    $commands = array_values(array_unique($cmdMatches[1] ?? []));
    foreach ($commands as $command) {
        if (!str_contains($supportedSvgDoc, '`' . $command . '`,') && !str_contains($supportedSvgDoc, '`' . $command . '`')) {
            $errors[] = sprintf('SVG path command missing from docs/reference/supported-svg.md: %s', $command);
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, '- ' . $error . PHP_EOL);
    }

    exit(1);
}

fwrite(STDOUT, "Documentation drift checks passed.\n");
