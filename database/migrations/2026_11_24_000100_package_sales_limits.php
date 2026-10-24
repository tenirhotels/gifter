<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $t) {
            $t->boolean('sales_limits_enabled')->default(false)->after('period_until');
            $t->unsignedInteger('sales_quota')->nullable()->after('sales_limits_enabled');
            $t->date('sales_from')->nullable()->after('sales_quota');
            $t->date('sales_until')->nullable()->after('sales_from');
        });
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn(['sales_limits_enabled', 'sales_quota', 'sales_from', 'sales_until']));
    }
};
