<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бронь по исключению: истёкший без брони сертификат возвращается в работу — Front Office ставит
 * бронь на свободную дату, директор по продажам согласует. Заявки не удаляются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('booking_number');
            $table->string('guest_name');
            $table->date('arrival_date');
            $table->date('departure_date');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_exceptions');
    }
};
