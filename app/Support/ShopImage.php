<?php

namespace App\Support;

/**
 * Фото витрины в нескольких размерах: `srcset` для разметки и создание WebP-копий нужной ширины
 * (ShopMediaController). Оригинал не меняется; копии лежат в storage/app/public/shop/v/<ширина>/.
 */
final class ShopImage
{
    /** Допустимые ширины копий (px): чужую ширину контроллер не принимает. */
    public const WIDTHS = [480, 720, 1200, 1600];

    /** Максимум пикселей исходника, который берём в память GD (защита от огромных файлов). */
    private const MAX_PIXELS = 40_000_000;

    /** `srcset` по имени файла: «/media/f.jpg?w=480 480w, …». @param  list<int>  $widths */
    public static function srcset(string $file, array $widths = self::WIDTHS): string
    {
        return collect($widths)->map(fn (int $w) => url('/media/'.basename($file)).'?w='.$w.' '.$w.'w')->implode(', ');
    }

    /** @return array{0: int, 1: int}|null ширина и высота оригинала */
    public static function size(string $path): ?array
    {
        $info = @getimagesize($path);

        return $info ? [(int) $info[0], (int) $info[1]] : null;
    }

    /** Создаёт (или берёт готовую) WebP-копию; null — если сделать нельзя, тогда отдаётся оригинал. */
    public static function webpVariant(string $source, string $targetDir, string $name, int $width): ?string
    {
        if (! in_array($width, self::WIDTHS, true) || ! function_exists('imagewebp') || ! is_file($source)) {
            return null;
        }
        $target = $targetDir.'/'.$width.'/'.$name.'.webp';
        if (is_file($target) && filemtime($target) >= filemtime($source)) {
            return $target;
        }
        $info = @getimagesize($source);
        if (! $info || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default => false,
        };
        if (! $image) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0))) {
                $image = $rotated;
            }
        }
        $w = imagesx($image);
        $h = imagesy($image);
        if ($w > $width) {
            $scaled = imagescale($image, $width, (int) round($h * $width / $w), IMG_BICUBIC);
            $image = $scaled ?: $image;
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        if (! is_dir(dirname($target))) {
            @mkdir(dirname($target), 0775, true);
        }
        $tmp = $target.'.'.getmypid().'.tmp';
        $ok = imagewebp($image, $tmp, 78);
        if (! $ok || ! @rename($tmp, $target)) {
            @unlink($tmp);

            return null;
        }

        return $target;
    }
}
