<?php

namespace App\Http\Controllers;

use App\Support\ShopImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Фото витрины из storage/app/public/shop — без зависимости от storage:link на хостинге. Только эта папка и только картинки.
 * `?w=480|720|1200|1600` — WebP-копия нужной ширины (создаётся при первом запросе); браузеру без WebP отдаётся оригинал.
 * Имена файлов случайные (новая загрузка — новый адрес), поэтому кеш — на год.
 */
class ShopMediaController extends Controller
{
    public function __invoke(Request $request, string $file): BinaryFileResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $file) === 1, 404);
        $disk = Storage::disk('public');
        abort_unless($disk->exists('shop/'.$file), 404);

        $headers = ['Cache-Control' => 'public, max-age=31536000, immutable', 'Vary' => 'Accept'];
        $width = (int) $request->query('w');
        if ($width > 0 && str_contains((string) $request->header('Accept'), 'image/webp')) {
            $variant = ShopImage::webpVariant($disk->path('shop/'.$file), $disk->path('shop/v'), pathinfo($file, PATHINFO_FILENAME), $width);
            if ($variant) {
                return response()->file($variant, $headers + ['Content-Type' => 'image/webp']);
            }
        }

        return response()->file($disk->path('shop/'.$file), $headers);
    }
}
