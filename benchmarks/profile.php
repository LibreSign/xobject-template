<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

$compiler = new XObjectTemplateCompiler();
$compiler->compile(new CompileRequest(
    html: '<div style="font-size:10">Profile run</div><p style="font-size:9">xdebug profile mode</p>',
));

echo "Profile run completed.\n";
