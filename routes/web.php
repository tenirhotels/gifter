<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PublicShopController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\ShopMediaController;
use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\PublicPage;
use Illuminate\Support\Facades\Route;

// Публичная страница: витрина онлайн-пакетов и заявка на сертификат. Сотрудники входят на /admin.
Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/media/{file}', ShopMediaController::class)->where('file', '[A-Za-z0-9._-]+')->name('shop.media');
Route::middleware(PublicPage::class)->group(function () {
    Route::get('/', [PublicShopController::class, 'index'])->name('shop.index');
    Route::get('/terms', [PublicShopController::class, 'terms'])->name('shop.terms');
    Route::get('/faq', [PublicShopController::class, 'faq'])->name('shop.faq');
    Route::get('/order/thanks', [PublicShopController::class, 'thanks'])->name('shop.thanks');
    Route::get('/order/{package}', [PublicShopController::class, 'order'])->whereNumber('package')->name('shop.order');
    Route::post('/order/{package}', [PublicShopController::class, 'store'])->whereNumber('package')->middleware('throttle:5,1')->name('shop.store');
});

// Публичная проверка сертификата по QR-коду. Ограничение частоты — защита от перебора.
Route::get('/v/{token}', VerifyCertificateController::class)
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware([PublicPage::class, 'throttle:30,1'])
    ->name('certificates.verify');

// Язык интерфейса: ru, kk, en.
Route::post('/locale/{locale}', LocaleController::class)->name('locale.switch');
