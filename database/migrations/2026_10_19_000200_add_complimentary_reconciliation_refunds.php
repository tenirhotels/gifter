<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('kind', 20)->default('sale')->after('status')->index();
            $table->text('complimentary_reason')->nullable()->after('kind');
            // У бесплатного сертификата покупателя может не быть.
            $table->foreignId('customer_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->after('cancelled_at');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')->constrained('users')->restrictOnDelete();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('requested')->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('KZT');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn('reconciled_at');
        });
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['kind', 'complimentary_reason']);
        });
    }
};
