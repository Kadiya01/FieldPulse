<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * A request handler. Invoked by Http\Kernel::handle().
 *
 * Controllers are stateless: all state lives in the database, which is what
 * lets the same code run identically under mod_php, PHP-FPM, LiteSpeed, and CLI.
 */
interface ActionInterface
{
    public function __invoke(Request $request): Response;
}
