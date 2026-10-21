<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Аналитика и связь витрины: GA4, Яндекс.Метрика и номер WhatsApp. Пусто — ничего не подключается.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('ga4_id', 20)->nullable();
            $table->string('metrika_id', 12)->nullable();
            $table->string('whatsapp_phone', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['ga4_id', 'metrika_id', 'whatsapp_phone']);
        });
    }
};
