<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Тариф PMS, по которому должны быть забронированы сертификаты (сверка при внесении брони). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pms_settings', function (Blueprint $table) {
            $table->string('certificate_rate_plan')->nullable()->default('Сертификат')->after('property_code');
        });
    }

    public function down(): void
    {
        Schema::table('pms_settings', function (Blueprint $table) {
            $table->dropColumn('certificate_rate_plan');
        });
    }
};
