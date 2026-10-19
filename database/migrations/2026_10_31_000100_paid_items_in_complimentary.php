<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Платные доп. услуги в бесплатном сертификате: оплата — признак строки состава.
 * К оплате заказчиком — сумма платных строк (у платного сертификата — всё); маркетинговый
 * расход бесплатного — себестоимость строк, которые заказчик не оплачивает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_items', function (Blueprint $table) {
            $table->boolean('paid_by_customer')->default(false)->after('from_package');
        });
        Schema::table('certificates', function (Blueprint $table) {
            $table->decimal('payable_amount', 12, 2)->default(0)->after('total_cost');
            $table->decimal('marketing_cost', 12, 2)->default(0)->after('payable_amount');
        });

        DB::table('certificate_items')
            ->whereIn('certificate_id', DB::table('certificates')->where('kind', 'sale')->select('id'))
            ->update(['paid_by_customer' => true]);
        DB::table('certificates')->where('kind', 'sale')->update(['payable_amount' => DB::raw('total_amount')]);
        DB::table('certificates')->where('kind', 'complimentary')->update(['marketing_cost' => DB::raw('total_cost')]);
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['payable_amount', 'marketing_cost']);
        });
        Schema::table('certificate_items', function (Blueprint $table) {
            $table->dropColumn('paid_by_customer');
        });
    }
};
