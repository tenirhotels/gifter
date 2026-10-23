<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $t) {
            $t->text('hero_intro')->nullable()->after('hero_subtitle');
            $t->text('contact_invite')->nullable()->after('hero_intro');
            $t->json('text_translations')->nullable()->after('contact_invite');
            $t->json('delivery_methods')->nullable()->after('text_translations');
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', fn (Blueprint $t) => $t->dropColumn(['hero_intro', 'contact_invite', 'text_translations', 'delivery_methods']));
    }
};
