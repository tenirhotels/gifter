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
            $table->string('kaspi_payment_id', 64)->nullable()->unique();
            $table->string('kaspi_status', 32)->nullable();
            $table->timestamp('kaspi_checked_at')->nullable();
            $table->string('kaspi_idempotency_key', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['kaspi_payment_id']);
            $table->dropColumn(['kaspi_payment_id', 'kaspi_status', 'kaspi_checked_at', 'kaspi_idempotency_key']);
        });
    }
};
