<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $t) {
            $t->string('validity_type', 10)->default('term')->after('description_translations');
            $t->date('period_from')->nullable()->after('validity_type');
            $t->date('period_until')->nullable()->after('period_from');
        });
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn(['validity_type', 'period_from', 'period_until']));
    }
};
