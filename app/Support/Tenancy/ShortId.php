<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/** Compact company ids (12 chars, lower-case base 36): they appear in database names, cache keys and tracking ids. */
final class ShortId implements UniqueIdentifierGenerator
{
    public static function generate($resource): string
    {
        return strtolower(Str::random(12));
    }
}
