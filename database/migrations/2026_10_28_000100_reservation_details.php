<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 6: текущая бронь сертификата — номер брони PMS (вносит Front Office, бронь
 * в PMS Gifter не создаёт), гость как в брони, даты, сверка имени и проверка Финансов.
 * Прежние брони — в истории сертификата.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('pms_booking_number', 64)->nullable()->index()->after('status');
            $table->string('booking_guest_name')->nullable()->after('pms_booking_number');
            $table->date('arrival_date')->nullable()->index()->after('booking_guest_name');
            $table->date('departure_date')->nullable()->after('arrival_date');
            $table->string('name_match', 20)->nullable()->after('departure_date');
            $table->text('reservation_note')->nullable()->after('name_match');
            $table->string('reservation_review_status', 20)->nullable()->index()->after('reservation_note');
            $table->foreignId('reservation_reviewed_by')->nullable()->after('reservation_review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reservation_reviewed_at')->nullable()->after('reservation_reviewed_by');
            $table->text('reservation_review_comment')->nullable()->after('reservation_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_reviewed_by');
            $table->dropColumn(['pms_booking_number', 'booking_guest_name', 'arrival_date', 'departure_date', 'name_match',
                'reservation_note', 'reservation_review_status', 'reservation_reviewed_at', 'reservation_review_comment']);
        });
    }
};
