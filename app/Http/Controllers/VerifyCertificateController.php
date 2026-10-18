<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use Illuminate\Http\Response;

/**
 * Публичная проверка по QR: только номер, статус, срок и пакет —
 * без имён, контактов и сумм.
 */
class VerifyCertificateController extends Controller
{
    public function __invoke(string $token): Response
    {
        $certificate = Certificate::query()->where('verification_token', $token)->whereNotNull('issued_at')->with('package')->first();

        [$statusText, $tone] = match ($certificate?->status) {
            CertificateStatus::Active, CertificateStatus::Reserved => ['Действителен', 'ok'],
            CertificateStatus::InUse => ['Используется', 'warn'],
            CertificateStatus::Used => ['Использован', 'bad'],
            CertificateStatus::Expired => ['Срок действия истёк', 'bad'],
            CertificateStatus::Cancelled => ['Аннулирован', 'bad'],
            default => ['Недействителен', 'bad'],
        };
        if ($certificate && $certificate->status === CertificateStatus::Active && $certificate->valid_until->lt(today())) {
            [$statusText, $tone] = ['Срок действия истёк', 'bad'];
        }

        return response()->view('certificates.verify', compact('certificate', 'statusText', 'tone'), $certificate ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex');
    }
}
