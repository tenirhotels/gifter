<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\KaspiYespay\KaspiYespayClient;
use App\Services\KaspiYespay\KaspiYespayException;
use Illuminate\Console\Command;

/**
 * Прогон песочницы yespay (только ключ sk_test_…): создаёт тестовые платежи через клиентский слой Gifter,
 * ждёт финальных статусов опросом GET /v1/payments/:id (не чаще раза в 3 секунды, до 60 секунд) и проверяет идемпотентность.
 * В базе Gifter ничего не создаёт (сертификаты не удаляются, поэтому тестовых заказов в боевой базе быть не должно).
 */
class KaspiYespaySelftest extends Command
{
    protected $signature = 'gifter:kaspi-yespay-selftest';

    protected $description = 'Проверить yespay в тестовом режиме (ключ sk_test_…): все сценарии, идемпотентность';

    /** Сценарий задаётся двумя последними цифрами номера покупателя. */
    private const SCENARIOS = [
        '+77001234500' => ['failed', 'failed ~5 c'],
        '+77001234511' => ['unknown', 'unknown ~5 c'],
        '+77001234577' => ['pending', 'pending навсегда'],
        '+77001234599' => ['expired', 'expired ~20 c'],
        '+77001234567' => ['paid', 'paid ~8 c'],
    ];

    public function handle(KaspiYespayClient $client): int
    {
        if (! KaspiYespayClient::configured()) {
            $this->error('Не настроено: KASPI_YESPAY_ENABLED=true, YESPAY_SECRET_KEY, YESPAY_WEBHOOK_SECRET.');

            return self::FAILURE;
        }
        if (! KaspiYespayClient::sandbox()) {
            $this->error('Нужен ключ песочницы sk_test_… — с боевым ключом команда не запускается.');

            return self::FAILURE;
        }
        $run = now()->format('ymdHis');
        $rows = [];
        $n = 0;
        foreach (self::SCENARIOS as $phone => [$expected, $label]) {
            $payment = $this->payment('T'.$run.++$n);
            try {
                $created = $client->createPayment($payment, $phone, 'Gifter sandbox');
                $final = $this->wait($client, $created['id'], $expected);
                $rows[] = [$label, $expected, $final, $final === $expected ? 'прошло' : 'НЕТ'];
            } catch (KaspiYespayException $e) {
                $rows[] = [$label, $expected, 'ошибка: '.$e->getMessage(), 'НЕТ'];
            }
        }

        $payment = $this->payment('T'.$run.'X');
        try {
            $first = $client->createPayment($payment, '+77001234577', 'Gifter sandbox');
            $second = $client->createPayment($payment, '+77001234577', 'Gifter sandbox');
            $same = $first['id'] === $second['id'];
            $rows[] = ['два создания с одним Idempotency-Key', 'один платёж', $same ? 'один платёж' : 'два разных платежа', $same ? 'прошло' : 'НЕТ'];
        } catch (KaspiYespayException $e) {
            $rows[] = ['два создания с одним Idempotency-Key', 'один платёж', 'ошибка: '.$e->getMessage(), 'НЕТ'];
        }

        $this->table(['Ветка', 'Ожидание', 'Факт', 'Итог'], $rows);
        $this->line('Не проверяется этой командой: POST /v1/webhooks/test (тело запроса в доступном описании не приведено) и обработка вебхуков — смотрите журнал и «Оплата» в карточке.');

        return collect($rows)->contains(fn ($r) => $r[3] !== 'прошло') ? self::FAILURE : self::SUCCESS;
    }

    private function payment(string $orderId): Payment
    {
        return new Payment(['amount' => '1000.00', 'invoice_number' => $orderId, 'kaspi_yespay_idempotency_key' => $orderId]);
    }

    /** Опрос не чаще раза в 3 секунды, максимум 60 секунд; «pending навсегда» — ожидаемо остаётся pending. */
    private function wait(KaspiYespayClient $client, string $id, string $expected): string
    {
        $status = 'pending';
        for ($waited = 0; $waited <= 60; $waited += 3) {
            $status = $client->getPayment($id)['status'];
            if (! in_array($status, ['created', 'pending', 'processing'], true) || ($expected === 'pending' && $waited >= 15)) {
                break;
            }
            sleep(3);
        }

        return $status;
    }
}
