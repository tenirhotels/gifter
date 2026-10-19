<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Список услуг из PMS (Exely, справочник Content API). Ключ — ID услуги в PMS: название может быть на разных языках, ID один. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pms_services', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64)->unique();
            $table->string('name');
            $table->string('kind', 40)->nullable();
            $table->string('status', 30)->nullable();
            $table->boolean('allowed_in_certificate')->default(false);
            $table->foreignId('service_id')->nullable()->unique()->constrained('services')->restrictOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pms_services');
    }
};
