<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Html\Node;
use LibreSign\XObjectTemplate\Layout\LinearLayoutEngine;
use PHPUnit\Framework\TestCase;

final class LinearLayoutEngineTest extends TestCase
{
    public function testLayoutSupportsNestedNodesImagesAndStyles(): void
    {
        $engine = new LinearLayoutEngine();
        $nodes = [
            new Node(
                tag: 'div',
                text: '',
                attributes: [
                    'style' => 'font-size:12;font-family:Times New Roman;font-weight:700;text-align:right;width:200',
                ],
                children: [
                    new Node(tag: 'span', text: 'Approved', attributes: []),
                    new Node(
                        tag: 'img',
                        text: '',
                        attributes: [
                            'src' => '/fixture/sign.png',
                            'style' => 'width:20px;height:20px',
                        ],
                    ),
                ],
            ),
        ];

        $result = $engine->layout($nodes, 240.0, 90.0);

        self::assertCount(1, $result->lines);
        self::assertCount(1, $result->images);
        self::assertSame('Approved', $result->lines[0]->text);
        self::assertSame('F1', $result->lines[0]->fontAlias);
        self::assertSame(8.0, $result->lines[0]->x);
        self::assertSame(78.0, $result->lines[0]->y);
        self::assertSame('/fixture/sign.png', $result->images[0]->source);
        self::assertSame('Im0', $result->images[0]->alias);
        self::assertEqualsWithDelta(51.0, $result->images[0]->y, 0.0001);
        self::assertEqualsWithDelta(15.0, $result->images[0]->width, 0.0001);
        self::assertEqualsWithDelta(15.0, $result->images[0]->height, 0.0001);
    }

    public function testLayoutUsesBreakNodesToMoveToTheNextLine(): void
    {
        $engine = new LinearLayoutEngine();
        $result = $engine->layout([
            new Node(tag: 'p', text: 'First line', attributes: []),
            new Node(tag: 'br', text: '', attributes: []),
            new Node(tag: 'span', text: 'Second line', attributes: []),
        ], 240.0, 90.0);

        self::assertCount(2, $result->lines);
        self::assertSame('First line', $result->lines[0]->text);
        self::assertSame('Second line', $result->lines[1]->text);
        self::assertLessThan($result->lines[0]->y, $result->lines[1]->y);
    }
}
