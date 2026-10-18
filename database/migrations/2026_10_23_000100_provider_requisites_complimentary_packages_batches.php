<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Замечания 23.10.2026: реквизиты внешнего поставщика (ИП/ИИН, ТОО/БИН),
 * бесплатные пакеты (маркетинг, блогеры, бартер) и выпуск бесплатных блоками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('legal_form', 20)->nullable()->after('type');
            $table->string('legal_name')->nullable()->after('legal_form');
            $table->char('tax_id', 12)->nullable()->after('legal_name')->index();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_complimentary')->default(false)->after('validity_unit')->index();
            $table->string('complimentary_purpose', 20)->nullable()->after('is_complimentary');
        });

        Schema::create('certificate_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('purpose', 20);
            $table->text('reason');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('complimentary_purpose', 20)->nullable()->after('complimentary_reason');
            $table->foreignId('batch_id')->nullable()->after('package_id')->constrained('certificate_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn('complimentary_purpose');
        });
        Schema::dropIfExists('certificate_batches');
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn(['is_complimentary', 'complimentary_purpose']));
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn(['legal_form', 'legal_name', 'tax_id']));
    }
};
