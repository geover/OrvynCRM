<?php

namespace App\Support;

/** The largest file this server's PHP will take in one upload (the smaller of upload_max_filesize and post_max_size). */
class UploadLimit
{
    public static function bytes(): int
    {
        $limits = array_filter([self::toBytes((string) ini_get('upload_max_filesize')), self::toBytes((string) ini_get('post_max_size'))], fn (int $b) => $b > 0);

        return $limits ? min($limits) : PHP_INT_MAX;
    }

    public static function label(): string
    {
        return rtrim(rtrim(number_format(self::bytes() / 1048576, 1), '0'), '.').' MB';
    }

    /** "8M", "2G", "512K" or plain bytes as a number of bytes. */
    private static function toBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
