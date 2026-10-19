<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Заявка поставщику: кто гости и где оказать услугу (для трансфера — откуда забрать). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_fulfillments', function (Blueprint $table) {
            $table->string('guests')->nullable()->after('scheduled_time');
            $table->string('location')->nullable()->after('guests');
        });
    }

    public function down(): void
    {
        Schema::table('service_fulfillments', function (Blueprint $table) {
            $table->dropColumn(['guests', 'location']);
        });
    }
};
