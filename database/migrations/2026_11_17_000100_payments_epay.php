<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Онлайн-оплата картой по ссылке (эквайринг банка): номер счёта в банке, ссылка, статус и id операции.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('epay_invoice_id', 15)->nullable()->unique();
            $table->string('epay_link_id', 64)->nullable();
            $table->string('epay_transaction_id', 64)->nullable();
            $table->text('pay_url')->nullable();
            $table->timestamp('pay_expires_at')->nullable()->index();
            $table->string('epay_status', 20)->nullable();
            $table->timestamp('epay_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['epay_invoice_id', 'epay_link_id', 'epay_transaction_id', 'pay_url', 'pay_expires_at', 'epay_status', 'epay_checked_at']);
        });
    }
};
