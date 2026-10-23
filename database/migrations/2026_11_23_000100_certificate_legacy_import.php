<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $t) {
            $t->string('legacy_number', 60)->nullable()->index()->after('number');
            $t->string('legacy_key', 64)->nullable()->unique()->after('legacy_number');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $t) => $t->dropColumn(['legacy_number', 'legacy_key']));
    }
};
