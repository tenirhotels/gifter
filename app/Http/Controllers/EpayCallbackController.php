<?php

namespace App\Http\Controllers;

use App\Services\Epay\EpayException;
use App\Services\EpayPayments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * postLink банка. Адрес публичный, тело не подписано, поэтому из него берётся только номер счёта, а факт оплаты
 * проверяется запросом в банк (EpayPayments::check). Ответ 200 уходит сразу, проверка — после ответа.
 * Если уведомление потеряется или проверка не удастся, платёж подхватит gifter:epay-reconcile.
 */
class EpayCallbackController extends Controller
{
    public function __invoke(Request $request, EpayPayments $payments): Response
    {
        $body = $request->isJson() ? (array) $request->json()->all() : $request->all();

        dispatch(function () use ($payments, $body) {
            try {
                $payments->handleCallback($body);
            } catch (EpayException $e) {
                Log::warning('Банк (оплата): уведомление не обработано, платёж проверит расписание', ['причина' => $e->getMessage()]);
            }
        })->afterResponse();

        return response('OK', 200);
    }
}
