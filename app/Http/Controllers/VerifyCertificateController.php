<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Публичная проверка по QR: только номер, статус, срок и пакет —
 * без имён, контактов и сумм.
 */
class VerifyCertificateController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        // Гость может открыть страницу на своём языке: ?lang=kk|en|ru.
        $lang = (string) $request->query('lang');
        if (array_key_exists($lang, config('gifter.locales'))) {
            app()->setLocale($lang);
        }

        $certificate = Certificate::query()->where('verification_token', $token)->whereNotNull('issued_at')->with('package')->first();

        [$statusText, $tone] = match ($certificate?->status) {
            CertificateStatus::Active, CertificateStatus::Reserved => [__('Действителен'), 'ok'],
            CertificateStatus::InUse => [__('Гость проживает'), 'warn'],
            CertificateStatus::Used => [__('Уже использован'), 'bad'],
            CertificateStatus::Expired => [__('Срок действия истёк'), 'bad'],
            CertificateStatus::Cancelled => [__('Аннулирован'), 'bad'],
            default => [__('Недействителен'), 'bad'],
        };
        if ($certificate && $certificate->status === CertificateStatus::Active && $certificate->valid_until->lt(today())) {
            [$statusText, $tone] = [__('Срок действия истёк'), 'bad'];
        }

        return response()->view('certificates.verify', compact('certificate', 'statusText', 'tone'), $certificate ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex');
    }
}
