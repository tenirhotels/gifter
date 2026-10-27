<?php

namespace App\Http\Controllers;

use App\Services\KaspiYespayPayments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Вебхук посредника по счетам Kaspi (yespay). Берётся СЫРОЕ тело (`getContent`), JSON не разбирается до проверки подписи.
 * Неверная подпись или событие старше 5 минут — 400 без каких-либо действий; остальное — 200, работа в очереди.
 * Потерянное уведомление подхватит gifter:kaspi-yespay-reconcile.
 */
class KaspiYespayWebhookController extends Controller
{
    public function __invoke(Request $request, KaspiYespayPayments $payments): Response
    {
        $code = $payments->receive($request->getContent(), [
            'signature' => (string) $request->header('X-Yespay-Signature'),
            'event_id' => (string) $request->header('X-Yespay-Event-Id'),
        ]);

        return response($code === 200 ? 'OK' : 'Bad request', $code);
    }
}
