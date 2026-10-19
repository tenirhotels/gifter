<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Проверка брони по PMS в день заезда: когда проверяли, итог и что расходится. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->timestamp('pms_checked_at')->nullable()->after('pms_data_age_minutes');
            $table->string('pms_check_status', 20)->nullable()->after('pms_checked_at');
            $table->text('pms_check_note')->nullable()->after('pms_check_status');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['pms_checked_at', 'pms_check_status', 'pms_check_note']);
        });
    }
};
