<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Случайный код для QR: номер сертификата угадывается, код — нет.
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('verification_token', 40)->nullable()->unique()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropColumn('verification_token');
        });
    }
};
