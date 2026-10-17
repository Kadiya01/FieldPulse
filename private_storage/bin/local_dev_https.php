<?php

declare(strict_types=1);

/*
 * PHP's built-in development server is HTTP-only, while the application
 * intentionally requires HTTPS. This shim is for loopback-only local testing.
 * Never use it with a network-facing server.
 */
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}

$_SERVER['HTTPS'] = 'on';
