<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Exception\UnsupportedSubsetException;
use LibreSign\XObjectTemplate\Html\SubsetHtmlParser;
use PHPUnit\Framework\TestCase;

final class SubsetHtmlParserTest extends TestCase
{
    public function testUnsupportedTagThrowsException(): void
    {
        $parser = new SubsetHtmlParser();

        $this->expectException(UnsupportedSubsetException::class);
        $parser->parse('<table><tr><td>x</td></tr></table>');
    }
}
