<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $t) {
            $t->json('faq_items')->nullable();
            $t->json('terms_items')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', fn (Blueprint $t) => $t->dropColumn(['faq_items', 'terms_items']));
    }
};
