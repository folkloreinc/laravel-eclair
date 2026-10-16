<?php

namespace Folklore\Eclair\Data;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
final class Dates
{
    public static function read(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
