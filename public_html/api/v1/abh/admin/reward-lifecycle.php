<?php
declare(strict_types=1);
require_once dirname(__DIR__, 5) . '/private_storage/bootstrap.php';
\FieldPulse\Http\Kernel::handle('abh.admin.reward.lifecycle');
