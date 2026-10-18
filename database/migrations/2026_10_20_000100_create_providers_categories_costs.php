<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 30)->index();
            $table->string('contact_person')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('description')->constrained('service_categories')->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->after('category_id')->constrained()->restrictOnDelete();
            $table->boolean('requires_fulfillment')->default(false)->after('is_active');
            $table->boolean('requires_scheduling')->default(false)->after('requires_fulfillment');
            $table->boolean('requires_confirmation')->default(false)->after('requires_scheduling');
        });

        // Себестоимость — часть версии цены: цена и себестоимость действуют на один период.
        Schema::table('service_prices', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->after('price');
        });

        // Снимок в строке сертификата: себестоимость, поставщик и требования к исполнению на дату продажи.
        Schema::table('certificate_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('line_total');
            $table->decimal('line_cost', 12, 2)->nullable()->after('unit_cost');
            $table->foreignId('provider_id')->nullable()->after('service_id')->constrained()->restrictOnDelete();
            $table->boolean('requires_fulfillment')->default(false)->after('line_cost');
            $table->boolean('requires_scheduling')->default(false)->after('requires_fulfillment');
            $table->boolean('requires_confirmation')->default(false)->after('requires_scheduling');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->decimal('total_cost', 12, 2)->default(0)->after('total_amount');
            $table->foreignId('complimentary_approved_by')->nullable()->after('complimentary_reason')->constrained('users')->restrictOnDelete();
            $table->timestamp('complimentary_approved_at')->nullable()->after('complimentary_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('complimentary_approved_by');
            $table->dropColumn(['total_cost', 'complimentary_approved_at']);
        });
        Schema::table('certificate_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn(['unit_cost', 'line_cost', 'requires_fulfillment', 'requires_scheduling', 'requires_confirmation']);
        });
        Schema::table('service_prices', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn(['requires_fulfillment', 'requires_scheduling', 'requires_confirmation']);
        });
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('providers');
    }
};
