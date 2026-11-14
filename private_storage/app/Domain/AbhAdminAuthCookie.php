<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Http\Response;

/** Cookie policy for administrator sessions; separate from agent refresh cookies. */
final class AbhAdminAuthCookie
{
    public const NAME = 'abh_admin_refresh';
    public const PATH = '/api/v1/abh/admin/auth';

    private function __construct()
    {
    }

    /** @return array{name:string,value:string,expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
    public static function cleared(): array
    {
        return Response::expiredRefreshCookie(self::NAME, self::PATH, true, 'Strict');
    }
}
