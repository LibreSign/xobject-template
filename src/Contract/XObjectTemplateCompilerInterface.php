<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Contract;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Dto\CompileResult;

interface XObjectTemplateCompilerInterface
{
    public function compile(CompileRequest $request): CompileResult;
}
