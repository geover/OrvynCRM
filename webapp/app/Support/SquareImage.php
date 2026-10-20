<?php

namespace App\Support;

/** A photo as a small square JPEG (the middle of the picture), made once when it is uploaded so showing it costs a few kilobytes. */
class SquareImage
{
    /**
     * A person's picture as a small square: a PNG when the original has see-through parts (an avatar on a transparent background stays that way), otherwise a JPEG.
     *
     * @return array{bytes: string, extension: string, mime: string}
     */
    public static function small(string $bytes, int $size = 256): array
    {
        $src = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
        if (! $src || ! self::hasTransparency($src)) {
            return ['bytes' => self::jpeg($bytes, $size), 'extension' => 'jpg', 'mime' => 'image/jpeg'];
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        abort_if($w * $h > 40_000_000, 422, 'That picture is too large.');
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagepng($dst, null, 7);

        return ['bytes' => (string) ob_get_clean(), 'extension' => 'png', 'mime' => 'image/png'];
    }

    /** The type a stored picture is served as, from its name. */
    public static function mimeOf(string $path): string
    {
        return str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
    }

    /** Whether any part of the picture is see-through (looked for on a grid of points, which is enough for a picture with a transparent background). */
    private static function hasTransparency(\GdImage $img): bool
    {
        if (! imageistruecolor($img)) {
            return imagecolortransparent($img) >= 0;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15] as $i) {
            foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15] as $j) {
                if (((imagecolorat($img, (int) (($w - 1) * $i / 15), (int) (($h - 1) * $j / 15)) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

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
