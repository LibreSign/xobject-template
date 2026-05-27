<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Integration;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisibleSignatureBusinessRuleTest extends TestCase
{
    #[DataProvider('signatureScenarioProvider')]
    public function testVisibleSignatureTemplateScenarios(string $html, int $maxStreamLength): void
    {
        $compiler = new XObjectTemplateCompiler();
        $result = $compiler->compile(new CompileRequest(html: $html, width: 260.0, height: 90.0));

        self::assertStringContainsString('BT', $result->contentStream);
        self::assertStringContainsString('ET', $result->contentStream);
        self::assertLessThan($maxStreamLength, strlen($result->contentStream));
        self::assertSame('/Helvetica', $result->resources['Font']['F1']['BaseFont']);
        self::assertGreaterThanOrEqual(0, $result->metadata['render_ms']);
    }

    /**
     * @return iterable<string, array{html: string, maxStreamLength: int}>
     */
    public static function signatureScenarioProvider(): iterable
    {
        yield 'signer name and status' => [
            'html' => '<div style="font-size:10">Signed by Demo User</div><p style="font-size:9">Document approved</p>',
            'maxStreamLength' => 1200,
        ];

        yield 'signer with image mark' => [
            'html' => '<img src="/fixture/sign.png" style="width:20px;height:20px" />'
                . '<span style="font-size:9">ID 42</span>',
            'maxStreamLength' => 1400,
        ];

        yield 'styled signer with alignment and spacing' => [
            'html' => '<div style="font-family:Times New Roman;font-weight:700;text-align:right;'
                . 'margin:6;padding:2;width:220">Signed by Styled User</div>',
            'maxStreamLength' => 1800,
        ];
    }
}
