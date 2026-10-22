<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Политика конфиденциальности: оператор данных и адрес уже действующей публичной оферты (внутренний документ).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('operator_name', 150)->nullable();
            $table->string('offer_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['operator_name', 'offer_url']);
        });
    }
};
