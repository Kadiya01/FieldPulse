<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Http\Response;

/** Cookie attributes shared by ABH agent login, refresh and logout. */
final class AbhAgentAuthCookie
{
    public const NAME = 'abh_refresh';
    public const PATH = '/api/v1/abh/auth';

    private function __construct()
    {
    }

    /** @return array{name:string,value:string,expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
    public static function cleared(): array
    {
        return Response::expiredRefreshCookie(self::NAME, self::PATH, true, 'Strict');
    }
}
