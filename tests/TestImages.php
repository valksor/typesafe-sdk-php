<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

/** Image bytes for tests. */
final class TestImages
{
    /** Not a decodable image: only the signature matters for content-type detection. */
    public const PNG = "\x89PNG\r\n\x1a\nimage data";
    public const JPEG = "\xff\xd8\xffimage data";

    /**
     * A solid-colour PNG, encoded in pure PHP.
     *
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    public static function png(int $width, int $height, int $red, int $green, int $blue): string
    {
        return self::encodePng($width, $height, str_repeat("\x00" . str_repeat(chr($red) . chr($green) . chr($blue), $width), $height));
    }

    /** A white square PNG with a red square covering its centre half, encoded in pure PHP. */
    public static function redSquareOnWhite(int $size): string
    {
        $edge = intdiv($size, 4);
        $rows = '';
        for ($y = 0; $y < $size; ++$y) {
            $inside = $y >= $edge && $y < $size - $edge;
            $rows .= "\x00" . ($inside
                ? str_repeat("\xff\xff\xff", $edge) . str_repeat("\xff\x00\x00", $size - 2 * $edge) . str_repeat("\xff\xff\xff", $edge)
                : str_repeat("\xff\xff\xff", $size));
        }

        return self::encodePng($size, $size, $rows);
    }

    /**
     * A solid-colour JPEG, or null without ext-gd.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    public static function jpeg(int $width, int $height, int $red, int $green, int $blue): ?string
    {
        if (!function_exists('imagejpeg')) {
            return null;
        }
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            return null;
        }
        $colour = imagecolorallocate($image, $red, $green, $blue);
        if ($colour === false) {
            return null;
        }
        imagefill($image, 0, 0, $colour);
        ob_start();
        imagejpeg($image, null, 95);
        $data = (string) ob_get_clean();

        return $data === '' ? null : $data;
    }

    private static function encodePng(int $width, int $height, string $rows): string
    {
        $chunk = static fn (string $kind, string $data): string => pack('N', strlen($data)) . $kind . $data . pack('N', crc32($kind . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($rows))
            . $chunk('IEND', '');
    }
}
