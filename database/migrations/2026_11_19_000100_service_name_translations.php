<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pms_services', fn (Blueprint $t) => $t->json('names')->nullable()->after('name'));
        Schema::table('services', fn (Blueprint $t) => $t->json('name_translations')->nullable()->after('name'));
        Schema::table('certificate_items', fn (Blueprint $t) => $t->json('service_name_translations')->nullable()->after('service_name_snapshot'));
    }

    public function down(): void
    {
        Schema::table('pms_services', fn (Blueprint $t) => $t->dropColumn('names'));
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('name_translations'));
        Schema::table('certificate_items', fn (Blueprint $t) => $t->dropColumn('service_name_translations'));
    }
};
