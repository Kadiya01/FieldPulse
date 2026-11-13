<?php

declare(strict_types=1);

define('ABH_BOOTSTRAP_RESOLVER_LIBRARY_ONLY', true);
require_once dirname(__DIR__, 2) . '/public_html/api/v1/_bootstrap.php';

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'abh-bootstrap-path-' . bin2hex(random_bytes(6));
$previewDocumentRoot = $base . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng';
$previewPrivateRoot = $base . DIRECTORY_SEPARATOR . 'abhpreview_private_storage';
$productionPrivateRoot = $base . DIRECTORY_SEPARATOR . 'private_storage';
$legacyBase = $base . DIRECTORY_SEPARATOR . 'legacy';
$legacyDocumentRoot = $legacyBase . DIRECTORY_SEPARATOR . 'public_html';
$legacyPrivateRoot = $legacyBase . DIRECTORY_SEPARATOR . 'private_storage';

$checks = 0;
$failures = [];

function bootstrapPathCheck(string $label, bool $passed): void
{
    global $checks, $failures;
    $checks++;
    if (!$passed) {
        $failures[] = $label;
        fwrite(STDERR, "FAIL: {$label}" . PHP_EOL);
        return;
    }
    fwrite(STDOUT, "PASS: {$label}" . PHP_EOL);
}

function bootstrapPathMkdir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create test fixture directory.');
    }
}

function bootstrapPathTouch(string $path): void
{
    bootstrapPathMkdir(dirname($path));
    if (file_put_contents($path, "<?php // test fixture\n") === false) {
        throw new RuntimeException('Unable to create test bootstrap fixture.');
    }
}

function bootstrapPathRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $items = new FilesystemIterator($path);
    foreach ($items as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            bootstrapPathRemoveTree($itemPath);
        } else {
            @unlink($itemPath);
        }
    }
    @rmdir($path);
}

try {
    bootstrapPathTouch($previewPrivateRoot . '/app/bootstrap.php');
    bootstrapPathTouch($productionPrivateRoot . '/app/bootstrap.php');
    bootstrapPathMkdir($previewDocumentRoot);

    $resolved = abhResolveApiBootstrap(
        $previewDocumentRoot,
        'monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng',
        $previewDocumentRoot,
        $previewPrivateRoot
    );
    bootstrapPathCheck(
        'preview selects its dedicated private bootstrap',
        $resolved === realpath($previewPrivateRoot . '/app/bootstrap.php')
    );

    // Removing the staging bootstrap must fail closed. The production sibling
    // deliberately remains present to prove the resolver never falls back.
    unlink($previewPrivateRoot . '/app/bootstrap.php');
    $resolved = abhResolveApiBootstrap(
        $previewDocumentRoot,
        'monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng',
        $previewDocumentRoot,
        $previewPrivateRoot
    );
    bootstrapPathCheck(
        'missing preview bootstrap does not fall back to production',
        $resolved === null
    );

    // A preview hostname served from a different document root is also a hard
    // failure, even when a production private_storage directory is discoverable.
    $resolved = abhResolveApiBootstrap(
        $legacyDocumentRoot,
        'monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng',
        $previewDocumentRoot,
        $previewPrivateRoot
    );
    bootstrapPathCheck(
        'preview hostname with unexpected document root fails closed',
        $resolved === null
    );

    // Private storage inside the public document root is prohibited.
    $nestedPrivateRoot = $previewDocumentRoot . DIRECTORY_SEPARATOR . 'private_storage';
    bootstrapPathTouch($nestedPrivateRoot . '/app/bootstrap.php');
    $resolved = abhResolveApiBootstrap(
        $previewDocumentRoot,
        'monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng',
        $previewDocumentRoot,
        $nestedPrivateRoot
    );
    bootstrapPathCheck(
        'preview private storage cannot be inside document root',
        $resolved === null
    );

    // Existing local/non-preview deployments still resolve a sibling
    // private_storage directory using the conventional layout.
    bootstrapPathTouch($legacyPrivateRoot . '/app/bootstrap.php');
    bootstrapPathMkdir($legacyDocumentRoot);
    $resolved = abhResolveApiBootstrap(
        $legacyDocumentRoot,
        'localhost',
        $previewDocumentRoot,
        $previewPrivateRoot
    );
    bootstrapPathCheck(
        'non-preview environment retains conventional private_storage lookup',
        $resolved === realpath($legacyPrivateRoot . '/app/bootstrap.php')
    );
} catch (Throwable $error) {
    $failures[] = 'test fixture execution: ' . $error->getMessage();
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
} finally {
    bootstrapPathRemoveTree($base);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf(
        "Bootstrap path tests failed: %d/%d checks failed.%s",
        count($failures),
        $checks,
        PHP_EOL
    ));
    exit(1);
}

fwrite(STDOUT, sprintf("Bootstrap path tests passed: %d checks.%s", $checks, PHP_EOL));
