<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit\Html;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Html\HtmlContextInterpolator;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\TestCase;

final class HtmlContextInterpolatorTest extends TestCase
{
    public function testInterpolateReplacesKnownPlaceholdersAndEscapesValues(): void
    {
        $interpolator = new HtmlContextInterpolator();

        $html = $interpolator->interpolate(
            '<div title="{{ signer }}">Signed by {{ signer }}</div>',
            ['signer' => 'Alice & Bob'],
        );

        self::assertSame(
            '<div title="Alice &amp; Bob">Signed by Alice &amp; Bob</div>',
            $html,
        );
    }

    public function testInterpolateLeavesUnknownPlaceholdersUntouched(): void
    {
        $interpolator = new HtmlContextInterpolator();

        $html = $interpolator->interpolate('<div>{{ signer }}</div><div>{{ issuer }}</div>', ['signer' => 'Alice']);

        self::assertSame('<div>Alice</div><div>{{ issuer }}</div>', $html);
    }

    public function testCompilerInterpolatesContextBeforeParsingHtml(): void
    {
        $compiler = new XObjectTemplateCompiler();

        $result = $compiler->compile(new CompileRequest(
            html: '<div style="font-size:10">Signed by {{ signer }}</div>',
            context: ['signer' => 'Preview User'],
        ));

        self::assertStringContainsString('Signed by Preview User', $result->contentStream);
    }
}
