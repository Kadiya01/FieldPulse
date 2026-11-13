<?php

declare(strict_types=1);

/**
 * Resolve the private application bootstrap without allowing the ABH preview
 * vhost to fall back to the production private_storage directory.
 *
 * Preview selection is based on the web server's canonical DOCUMENT_ROOT as
 * well as the preview host. If the preview host is served from an unexpected
 * document root, or its dedicated private directory is missing/redirected,
 * resolution fails closed.
 *
 * The optional path arguments make the resolver testable with temporary
 * directory fixtures. Runtime callers should use the defaults.
 */
function abhResolveApiBootstrap(
    string $documentRoot,
    string $requestHost,
    ?string $previewDocumentRoot = null,
    ?string $previewPrivateRoot = null,
    string $previewHost = 'monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng'
): ?string {
    $previewDocumentRoot ??= '/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng';
    $previewPrivateRoot ??= '/home/abhgloba/abhpreview_private_storage';

    $documentRootReal = $documentRoot !== '' ? realpath($documentRoot) : false;
    $previewDocumentRootReal = realpath($previewDocumentRoot);

    $host = strtolower(trim($requestHost));
    $host = preg_replace('/:\\d+$/', '', $host) ?? $host;
    $host = rtrim($host, '.');
    $previewHost = strtolower(rtrim($previewHost, '.'));

    $isPreviewHost = $host !== '' && hash_equals($previewHost, $host);
    $isPreviewDocumentRoot = $documentRootReal !== false
        && $previewDocumentRootReal !== false
        && $documentRootReal === $previewDocumentRootReal;

    if ($isPreviewHost || $isPreviewDocumentRoot) {
        // Never perform the generic upward search for the preview vhost. If
        // staging is misconfigured, do not silently load production code.
        if (
            $documentRootReal === false
            || $previewDocumentRootReal === false
            || $documentRootReal !== $previewDocumentRootReal
        ) {
            return null;
        }

        $configuredPrivateRoot = rtrim($previewPrivateRoot, '/\\');
        $privateRootReal = realpath($configuredPrivateRoot);

        // Reject a symlink or non-canonical path. In particular, a staging
        // directory symlinked to /home/abhgloba/private_storage is forbidden.
        if (
            $privateRootReal === false
            || $privateRootReal !== $configuredPrivateRoot
            || !is_dir($privateRootReal)
        ) {
            return null;
        }

        // Private storage and the web document root must be separate siblings,
        // not nested inside one another.
        if (
            $privateRootReal === $documentRootReal
            || str_starts_with($privateRootReal, $documentRootReal . DIRECTORY_SEPARATOR)
            || str_starts_with($documentRootReal, $privateRootReal . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        $appDirectory = realpath($privateRootReal . DIRECTORY_SEPARATOR . 'app');
        $bootstrap = realpath(
            $privateRootReal . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'bootstrap.php'
        );

        if (
            $appDirectory === false
            || $bootstrap === false
            || !is_file($bootstrap)
            || dirname($bootstrap) !== $appDirectory
            || !str_starts_with($bootstrap, $privateRootReal . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return $bootstrap;
    }

    // Local development and existing non-preview installations retain the
    // conventional sibling private_storage layout.
    $cursor = $documentRootReal !== false ? $documentRootReal : realpath(__DIR__);
    if ($cursor === false) {
        return null;
    }

    for ($level = 0; $level < 9; $level++) {
        $privateRoot = realpath($cursor . DIRECTORY_SEPARATOR . 'private_storage');
        $bootstrap = realpath(
            $cursor . DIRECTORY_SEPARATOR . 'private_storage'
            . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'bootstrap.php'
        );

        if (
            $privateRoot !== false
            && $bootstrap !== false
            && is_file($bootstrap)
            && str_starts_with($bootstrap, $privateRoot . DIRECTORY_SEPARATOR)
        ) {
            return $bootstrap;
        }

        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
    }

    return null;
}

// Tests include this file in library-only mode to exercise the resolver
// without invoking the application bootstrap.
if (
    defined('ABH_BOOTSTRAP_RESOLVER_LIBRARY_ONLY')
    && ABH_BOOTSTRAP_RESOLVER_LIBRARY_ONLY === true
) {
    return;
}

// The helper is included by route shims; it must not be a directly callable
// application endpoint.
$requestedScript = isset($_SERVER['SCRIPT_FILENAME'])
    ? realpath((string) $_SERVER['SCRIPT_FILENAME'])
    : false;
$self = realpath(__FILE__);
if ($requestedScript !== false && $self !== false && $requestedScript === $self) {
    http_response_code(404);
    exit('Not found.');
}

$documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$bootstrap = abhResolveApiBootstrap($documentRoot, $requestHost);

if ($bootstrap === null) {
    http_response_code(500);
    exit('Service unavailable.');
}

require_once $bootstrap;

unset($requestedScript, $self, $documentRoot, $requestHost, $bootstrap);
