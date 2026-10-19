<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\VerifyCertificateController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Публичная проверка сертификата по QR-коду. Ограничение частоты — защита от перебора.
Route::get('/v/{token}', VerifyCertificateController::class)
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware('throttle:30,1')
    ->name('certificates.verify');

// Язык интерфейса: ru, kk, en.
Route::get('/locale/{locale}', LocaleController::class)->name('locale.switch');
