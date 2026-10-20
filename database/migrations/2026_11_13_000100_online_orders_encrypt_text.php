<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Получатель и поздравление хранятся зашифрованными (шифртекст длиннее исходного — нужен text). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->text('recipient_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->string('recipient_name')->nullable()->change();
        });
    }
};
