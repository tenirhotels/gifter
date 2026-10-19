<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Интеграция с PMS через Hotel Hub (или прямой Exely): настройки подключения и отметка «бронь подтверждена PMS». */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pms_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('base_url')->nullable();
            $table->string('property_code')->nullable();
            $table->text('token')->nullable(); // зашифрован (cast encrypted)
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->timestamp('pms_verified_at')->nullable()->after('pms_booking_number');
            $table->unsignedInteger('pms_data_age_minutes')->nullable()->after('pms_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['pms_verified_at', 'pms_data_age_minutes']);
        });
        Schema::dropIfExists('pms_settings');
    }
};
