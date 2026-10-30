<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Настройки формы заказа на сайте и корпоративные заявки (компания и БИН).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $t) {
            $t->boolean('order_show_recipient')->default(true);
            $t->boolean('order_corporate_enabled')->default(true);
        });
        Schema::table('online_orders', function (Blueprint $t) {
            $t->boolean('is_corporate')->default(false);
            $t->string('company', 255)->nullable();
            $t->string('bin', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', fn (Blueprint $t) => $t->dropColumn(['is_corporate', 'company', 'bin']));
        Schema::table('shop_settings', fn (Blueprint $t) => $t->dropColumn(['order_show_recipient', 'order_corporate_enabled']));
    }
};
