<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Витрина: фото пакета, получение сертификата (самовывоз / курьер / PDF), поздравление и настройки оформления сайта. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });

        Schema::table('online_orders', function (Blueprint $table) {
            $table->string('delivery_method', 10)->default('pickup')->after('recipient_name');
            $table->text('delivery_address')->nullable()->after('delivery_method');
            $table->text('gift_message')->nullable()->after('delivery_address');
        });

        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->string('hero_image_path')->nullable();
            $table->string('hero_title')->nullable();
            $table->string('hero_subtitle', 500)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
        Schema::table('online_orders', fn (Blueprint $table) => $table->dropColumn(['delivery_method', 'delivery_address', 'gift_message']));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('image_path'));
    }
};
