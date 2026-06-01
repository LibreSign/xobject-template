<?php

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\XObjectTemplate\Benchmarks;

use LibreSign\XObjectTemplate\Dto\CompileRequest;
use LibreSign\XObjectTemplate\XObjectTemplateCompiler;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Warmup(1)]
#[Revs(5)]
#[Iterations(10)]
#[OutputTimeUnit('milliseconds')]
class CompilerBench
{
    private XObjectTemplateCompiler $compiler;

    public function setup(): void
    {
        $this->compiler = new XObjectTemplateCompiler();
    }

    public function benchSimpleHtml(): void
    {
        $html = '<div style="font-size:10;color:#000">Signed by Demo User</div><p style="font-size:9">Document approved</p>';
        $this->compiler->compile(new CompileRequest(html: $html, width: 240, height: 84));
    }

    public function benchComplexHtml(): void
    {
        $html = <<<'HTML'
<div style="font-size:12;font-weight:bold;color:#003366;margin-bottom:10px">
  Certificate of Approval
</div>
<p style="font-size:10;line-height:1.5">
  This document has been signed by:<br/>
  <strong>John Doe</strong><br/>
  Date: 2026-06-01<br/>
  Status: <span style="color:green">Verified</span>
</p>
<div style="border:1px solid #ccc;padding:8px;margin-top:10px;font-size:9;background:#f9f9f9">
  <p>Signature validated and timestamp recorded.</p>
</div>
HTML;
        $this->compiler->compile(new CompileRequest(html: $html, width: 280, height: 120));
    }
}
