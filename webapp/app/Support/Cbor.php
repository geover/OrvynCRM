<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A small CBOR (RFC 8949) reader: just what a passkey sends (maps, arrays, byte and text strings, integers). Anything it does not understand
 * is refused rather than guessed.
 */
class Cbor
{
    private const MAX_DEPTH = 8;

    public static function decode(string $data): mixed
    {
        $offset = 0;
        $value = self::read($data, $offset, 0);
        if ($offset !== strlen($data)) {
            throw new InvalidArgumentException('Unexpected data after the CBOR value.');
        }

        return $value;
    }

    /**
     * Reads one value and reports where it ended, for data that carries something else after it (the key inside a passkey's authenticator data).
     *
     * @return array{0: mixed, 1: int}
     */
    public static function decodeFirst(string $data, int $from = 0): array
    {
        $offset = $from;

        return [self::read($data, $offset, 0), $offset];
    }

    private static function read(string $d, int &$o, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH || $o >= strlen($d)) {
            throw new InvalidArgumentException('Malformed CBOR.');
        }
        $initial = ord($d[$o++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;
        $length = self::length($d, $o, $info);

        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2 => self::take($d, $o, $length),
            3 => self::take($d, $o, $length),
            4 => self::items($d, $o, $length, $depth),
            5 => self::pairs($d, $o, $length, $depth),
            7 => match ($info) {
                20 => false, 21 => true, 22, 23 => null,
                default => throw new InvalidArgumentException('Unsupported CBOR value.'),
            },
            default => throw new InvalidArgumentException('Unsupported CBOR type.'),
        };
    }

    private static function length(string $d, int &$o, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new InvalidArgumentException('Unsupported CBOR length.'),
        };
        $raw = self::take($d, $o, $bytes);
        $n = 0;
        foreach (str_split($raw) as $b) {
            $n = ($n << 8) | ord($b);
        }
        if ($n < 0) {
            throw new InvalidArgumentException('CBOR number too large.');
        }

        return $n;
    }

    private static function take(string $d, int &$o, int $n): string
    {
        if ($n > strlen($d) - $o) {
            throw new InvalidArgumentException('Truncated CBOR.');
        }
        $s = substr($d, $o, $n);
        $o += $n;

        return $s;
    }

    /** @return list<mixed> */
    private static function items(string $d, int &$o, int $count, int $depth): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = self::read($d, $o, $depth + 1);
        }

        return $out;
    }

    /** @return array<int|string, mixed> */
    private static function pairs(string $d, int &$o, int $count, int $depth): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $key = self::read($d, $o, $depth + 1);
            if (! is_int($key) && ! is_string($key)) {
                throw new InvalidArgumentException('Unsupported CBOR map key.');
            }
            $out[$key] = self::read($d, $o, $depth + 1);
        }

        return $out;
    }
}
