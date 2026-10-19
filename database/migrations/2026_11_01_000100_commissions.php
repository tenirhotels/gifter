<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Комиссия продавцов за погашенные сертификаты: продавец сертификата, версионные правила по пакетам,
 * снимок комиссии на сертификате, выплаты. Комиссия начисляется не при продаже, а при погашении.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('sold_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
        });
        DB::table('certificates')->update(['sold_by' => DB::raw('created_by')]);

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);
            $table->decimal('rate', 6, 2)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['package_id', 'valid_from']);
        });

        Schema::create('certificate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->index();
            // Снимок на момент выпуска: правило, тип, ставка, цена продажи и рассчитанная сумма не пересчитываются.
            $table->foreignId('rule_id')->nullable()->constrained('commission_rules')->restrictOnDelete();
            $table->string('rule_type', 10);
            $table->decimal('rule_rate', 6, 2)->nullable();
            $table->decimal('rule_fixed_amount', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamp('earned_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained('certificate_commissions')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payments');
        Schema::dropIfExists('certificate_commissions');
        Schema::dropIfExists('commission_rules');
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sold_by');
        });
    }
};
