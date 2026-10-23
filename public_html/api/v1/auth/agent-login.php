<?php

declare(strict_types=1);

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

\FieldPulse\Http\Kernel::handle('auth.agent_login');
