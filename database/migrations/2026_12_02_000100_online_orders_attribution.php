<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Откуда пришёл посетитель (UTM-метки и сайт-источник) — для сквозной конверсии «источник → оплата».
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->string('utm_source', 100)->nullable();
            $t->string('utm_medium', 100)->nullable();
            $t->string('utm_campaign', 100)->nullable();
            $t->string('referrer_host', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', fn (Blueprint $t) => $t->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'referrer_host']));
    }
};
