<?php

declare(strict_types=1);

/**
 * POST /api/v1/auth/login.php
 *
 * Three-line shim, described in Http\Kernel: resolve the application from
 * outside the document root, then hand the route name to the kernel. Keeping
 * every entry point this thin means a misconfigured .htaccess exposes at
 * most a file containing no logic, no configuration, and no credentials.
 *
 * The require path is relative to this file and points at the deployment
 * root, so the app/ tree stays outside public_html regardless of how deep
 * the document root is mounted.
 */

require_once dirname(__DIR__, 4) . '/private_storage/app/bootstrap.php';

\FieldPulse\Http\Kernel::handle('auth.login');
