<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PublicShopController;
use App\Http\Controllers\VerifyCertificateController;
use Illuminate\Support\Facades\Route;

// Публичная страница: витрина онлайн-пакетов и заявка на сертификат. Сотрудники входят на /admin.
Route::get('/', [PublicShopController::class, 'index'])->name('shop.index');
Route::get('/order/thanks', [PublicShopController::class, 'thanks'])->name('shop.thanks');
Route::get('/order/{package}', [PublicShopController::class, 'order'])->whereNumber('package')->name('shop.order');
Route::post('/order/{package}', [PublicShopController::class, 'store'])->whereNumber('package')->middleware('throttle:5,1')->name('shop.store');

// Публичная проверка сертификата по QR-коду. Ограничение частоты — защита от перебора.
Route::get('/v/{token}', VerifyCertificateController::class)
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware('throttle:30,1')
    ->name('certificates.verify');

// Язык интерфейса: ru, kk, en.
Route::post('/locale/{locale}', LocaleController::class)->name('locale.switch');
