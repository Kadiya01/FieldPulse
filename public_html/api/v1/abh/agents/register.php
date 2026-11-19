<?php

declare(strict_types=1);

/**
 * ABH Monthly Enrollment registration endpoint.
 * Uses the preview-aware loader and a separate ABH route; the legacy
 * /agents/register.php entry point is intentionally preserved.
 */
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

\FieldPulse\Http\Kernel::handle('abh.agent.register');
