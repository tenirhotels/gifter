<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Два источника брони на выбор: Hotel Hub (токен) или родной Exely (OAuth: client_id + client_secret). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pms_settings', function (Blueprint $table) {
            $table->string('provider', 10)->default('hub')->after('enabled');
            $table->string('client_id')->nullable()->after('token');
            $table->text('client_secret')->nullable()->after('client_id'); // зашифрован
        });
    }

    public function down(): void
    {
        Schema::table('pms_settings', function (Blueprint $table) {
            $table->dropColumn(['provider', 'client_id', 'client_secret']);
        });
    }
};
