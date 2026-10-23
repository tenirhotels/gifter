<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', fn (Blueprint $t) => $t->json('description_translations')->nullable()->after('name_translations'));
        Schema::table('packages', function (Blueprint $t) {
            $t->json('name_translations')->nullable()->after('name');
            $t->json('description_translations')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('description_translations'));
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn(['name_translations', 'description_translations']));
    }
};
