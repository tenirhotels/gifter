<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'recipients'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->text('phone2')->nullable();
                $t->string('phone2_hash', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'recipients'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['phone2', 'phone2_hash']));
        }
    }
};
