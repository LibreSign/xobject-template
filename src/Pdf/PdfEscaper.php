<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Pdf;

final class PdfEscaper
{
    public function escapeLiteralString(string $value): string
    {
        return str_replace(
            ['\\\\', '(', ')'],
            ['\\\\\\\\', '\\(', '\\)'],
            $value,
        );
    }
}
