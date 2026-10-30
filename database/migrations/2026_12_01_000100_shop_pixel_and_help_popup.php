<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Meta Pixel и всплывающее окно «Затрудняетесь?» на витрине.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $t) {
            $t->string('meta_pixel_id', 20)->nullable();
            $t->boolean('help_popup_enabled')->default(false);
            $t->unsignedSmallInteger('help_popup_delay')->default(60);
            $t->string('help_popup_title', 120)->nullable();
            $t->text('help_popup_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', fn (Blueprint $t) => $t->dropColumn(['meta_pixel_id', 'help_popup_enabled', 'help_popup_delay', 'help_popup_title', 'help_popup_text']));
    }
};
