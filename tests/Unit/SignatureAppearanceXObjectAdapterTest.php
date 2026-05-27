<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Tests\Unit;

use LibreSign\XObjectTemplate\Dto\CompileResult;
use LibreSign\XObjectTemplate\Integration\SignatureAppearanceXObjectAdapter;
use PHPUnit\Framework\TestCase;

final class SignatureAppearanceXObjectAdapterTest extends TestCase
{
    public function testAdapterMapsToExpectedPayload(): void
    {
        $adapter = new SignatureAppearanceXObjectAdapter();
        $result = new CompileResult(
            contentStream: 'BT\n(Foo) Tj\nET',
            resources: ['Font' => ['F1' => ['BaseFont' => '/Helvetica']]],
            bbox: [0.0, 0.0, 240.0, 84.0],
        );

        $payload = $adapter->toPdfSignerPayload($result);

        self::assertSame('BT\n(Foo) Tj\nET', $payload['stream']);
        self::assertSame([0.0, 0.0, 240.0, 84.0], $payload['bbox']);
    }
}
