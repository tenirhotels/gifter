<?php

namespace App\Http\Controllers;

use App\Services\Kaspi\KaspiException;
use App\Services\KaspiPayments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Вебхук посредника по счетам Kaspi. Подпись проверяется по СЫРОМУ телу сразу (неверная — 401), 200 уходит быстро,
 * проверка статуса в API — после ответа. Потерянное уведомление подхватит gifter:kaspi-reconcile.
 */
class KaspiWebhookController extends Controller
{
    public function __invoke(Request $request, KaspiPayments $payments): Response
    {
        $raw = $request->getContent();
        if (! $payments->validSignature($raw, (string) $request->header('X-Yespay-Signature'))) {
            return response('Invalid signature', 401);
        }
        $headers = ['signature' => (string) $request->header('X-Yespay-Signature'), 'event_id' => (string) $request->header('X-Yespay-Event-Id')];

        dispatch(function () use ($payments, $raw, $headers) {
            try {
                $payments->handleWebhook($raw, $headers);
            } catch (KaspiException $e) {
                Log::warning('Kaspi (счета): уведомление не обработано, платёж проверит расписание', ['причина' => $e->getMessage()]);
            }
        })->afterResponse();

        return response('OK', 200);
    }
}
