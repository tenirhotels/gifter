<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Счёт Kaspi Pay через посредника: id платежа у посредника, его статус и ключ идемпотентности создания.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('kaspi_yespay_payment_id', 64)->nullable()->unique();
            $table->string('kaspi_yespay_status', 32)->nullable();
            $table->timestamp('kaspi_yespay_checked_at')->nullable();
            $table->string('kaspi_yespay_idempotency_key', 64)->nullable();
            $table->timestamp('needs_review_at')->nullable();
        });
        // Обработанные уведомления посредника: повторная доставка того же события ничего не выполняет.
        Schema::create('yespay_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('type', 64)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yespay_webhook_events');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['kaspi_yespay_payment_id']);
            $table->dropColumn(['kaspi_yespay_payment_id', 'kaspi_yespay_status', 'kaspi_yespay_checked_at', 'kaspi_yespay_idempotency_key', 'needs_review_at']);
        });
    }
};
