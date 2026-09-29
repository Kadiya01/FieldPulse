<?php

declare(strict_types=1);

/**
 * GET /api/v1/submission.php?uuid=<submission_uuid>
 *
 * Three-line shim, described in Http\Kernel: resolve the application from
 * outside the document root, then hand the route name to the kernel. Keeping
 * every entry point this thin means a misconfigured .htaccess exposes at most a
 * file containing no logic, no configuration, and no credentials.
 *
 * The submission is identified by a query parameter rather than a path segment
 * because every other endpoint in this API is a flat .php shim, not a
 * REST-resource tree. The kernel dispatches on a route name passed by the shim,
 * so a {uuid} path segment would mean adding path-parameter routing for one
 * endpoint and nothing else uses it.
 *
 * The require path is relative to this file and points at the deployment
 * root, so the app/ tree stays outside public_html regardless of how deep
 * the document root is mounted.
 */

require_once dirname(__DIR__, 3) . '/private_storage/app/bootstrap.php';

\FieldPulse\Http\Kernel::handle('submission.status');
