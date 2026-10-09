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
 * The entry point walks upward from this file to locate private_storage/app/
 * bootstrap.php, so the app/ tree stays outside public_html regardless of how
 * deep the document root is mounted.
 */

$fpDir = rtrim(str_replace('\\', '/', __DIR__), '/');
$fpRoot = null;
for ($fpI = 0; $fpI < 8; $fpI++) {
    if (is_file($fpDir . '/private_storage/app/bootstrap.php')) {
        $fpRoot = $fpDir;
        break;
    }
    $fpParent = dirname($fpDir);
    if ($fpParent === $fpDir) { break; }
    $fpDir = $fpParent;
}
if ($fpRoot === null) {
    http_response_code(500);
    exit('Service unavailable.');
}
require_once $fpRoot . '/private_storage/app/bootstrap.php';
unset($fpDir, $fpRoot, $fpI, $fpParent);

\FieldPulse\Http\Kernel::handle('auth.login');
