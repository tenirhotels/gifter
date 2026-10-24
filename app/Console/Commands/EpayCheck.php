<?php

namespace App\Console\Commands;

use App\Services\Epay\EpayClient;
use App\Services\Epay\EpayException;
use Illuminate\Console\Command;

class EpayCheck extends Command
{
    protected $signature = 'gifter:epay-check';

    protected $description = 'Проверить настройку оплаты картой: окружение, ID (последние 4 символа) и получение токена. Секреты не показываются';

    public function handle(EpayClient $client): int
    {
        if (! EpayClient::configured()) {
            $this->error('Не заданы EPAY_CLIENT_ID, EPAY_CLIENT_SECRET, EPAY_TERMINAL или неверен EPAY_ENV (test|prod).');

            return self::FAILURE;
        }
        try {
            $info = $client->diagnose();
        } catch (EpayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line('Окружение: '.$info['env']);
        $this->line('EPAY_TERMINAL: '.$info['terminal']);
        $this->line('shop_id в счёте: '.$info['shop_id'].($info['shop_separate'] ? ' (EPAY_SHOP_ID)' : ' (= терминал; EPAY_SHOP_ID не задан)'));
        $this->info('Токен получен: ключи и окружение согласованы. Если банк всё равно отвечает 400 по shopId — уточните у банка значение shop_id для этого терминала.');

        return self::SUCCESS;
    }
}
