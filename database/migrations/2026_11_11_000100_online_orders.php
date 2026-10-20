<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Онлайн-заявки с публичной страницы и отметка «Продаётся онлайн» у пакета. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('sold_online')->default(false)->after('is_active');
        });

        Schema::create('online_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('full_name');
            $table->text('phone');
            $table->char('phone_hash', 64)->nullable()->index();
            $table->text('email')->nullable();
            $table->char('email_hash', 64)->nullable()->index();
            $table->string('recipient_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('locale', 5)->default('ru');
            $table->string('status', 20)->default('new')->index();
            $table->foreignId('certificate_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_orders');
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('sold_online'));
    }
};
