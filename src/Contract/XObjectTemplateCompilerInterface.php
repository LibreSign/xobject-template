<?php

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Contract;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\Dto\CompileResult;

interface XObjectTemplateCompilerInterface
{
    public function compile(CompileRequest $request): CompileResult;
}
