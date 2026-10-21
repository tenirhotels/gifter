<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Канал продажи (продавец или сайт), связь с заявкой и отметки напоминаний. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('source', 10)->default('staff')->index()->after('kind');
            $table->foreignId('online_order_id')->nullable()->after('source')->constrained()->nullOnDelete();
            $table->timestamp('expiry_reminded_at')->nullable();
        });
        Schema::table('online_orders', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('handled_at');
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', fn (Blueprint $table) => $table->dropColumn('reminded_at'));
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('online_order_id');
            $table->dropColumn(['source', 'expiry_reminded_at']);
        });
    }
};
