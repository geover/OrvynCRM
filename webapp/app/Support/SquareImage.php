<?php

namespace App\Support;

/** A photo as a small square JPEG (the middle of the picture), made once when it is uploaded so showing it costs a few kilobytes. */
class SquareImage
{
    public static function jpeg(string $bytes, int $size = 256): string
    {
        if (! function_exists('imagecreatefromstring')) {
            abort_if(strlen($bytes) > 400 * 1024, 422, 'This server cannot resize pictures; use one under 400 KB.');

            return $bytes;
        }
        $src = @imagecreatefromstring($bytes);
        abort_unless($src, 422, 'That is not a picture we can read.');
        [$w, $h] = [imagesx($src), imagesy($src)];
        abort_if($w * $h > 40_000_000, 422, 'That picture is too large.');
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));                    // a transparent picture sits on white
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagejpeg($dst, null, 82);

        return (string) ob_get_clean();
    }
}
