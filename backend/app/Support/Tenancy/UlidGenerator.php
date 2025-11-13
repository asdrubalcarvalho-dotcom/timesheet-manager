<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

class UlidGenerator implements UniqueIdentifierGenerator
{
    public static function generate($resource = null): string
    {
        return (string) Str::ulid();
    }
}
