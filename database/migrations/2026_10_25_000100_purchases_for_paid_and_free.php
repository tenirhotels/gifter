<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Замечание 25.10.2026: несколько сертификатов в одной продаже — и платных, и бесплатных.
 * Блок бесплатных становится «покупкой»; у платной покупки один счёт на всю сумму,
 * разложенный по сертификатам (payments.batch_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_batches', function (Blueprint $table) {
            $table->string('kind', 20)->default('complimentary')->after('package_id');
            $table->string('purpose', 20)->nullable()->change();
            $table->text('reason')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('certificate_id')->constrained('certificate_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('batch_id'));
        Schema::table('certificate_batches', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
