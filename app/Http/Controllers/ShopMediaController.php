<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Фото витрины из storage/app/public/shop — без зависимости от storage:link на хостинге. Только эта папка и только картинки. */
class ShopMediaController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $file) === 1, 404);
        $disk = Storage::disk('public');
        abort_unless($disk->exists('shop/'.$file), 404);

        return response()->file($disk->path('shop/'.$file), ['Cache-Control' => 'public, max-age=604800']);
    }
}
