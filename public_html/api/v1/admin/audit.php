<?php

declare(strict_types=1);

/**
 * Thin API route shim. The shared loader selects the preview private directory
 * by canonical document root and fails closed if the staging bootstrap is absent.
 */
require_once dirname(__DIR__) . '/_bootstrap.php';

\FieldPulse\Http\Kernel::handle('admin.audit');
