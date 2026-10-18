<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 5: заявки на исполнение услуг сертификата (ужин, трансфер, билеты…).
 * Статус заявки — подготовка услуги, а не частичное погашение: сертификат гасится только целиком.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            // Снимок признаков услуги на момент создания заявки.
            $table->boolean('requires_scheduling')->default(false);
            $table->boolean('requires_confirmation')->default(false);
            $table->date('scheduled_date')->nullable()->index();
            $table->string('scheduled_time', 5)->nullable();
            $table->text('request_details')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Уже забронированные и заехавшие сертификаты получают заявки, как если бы бронь отметили сейчас.
        $items = DB::table('certificate_items')
            ->join('certificates', 'certificates.id', '=', 'certificate_items.certificate_id')
            ->whereIn('certificates.status', ['reserved', 'in_use'])
            ->where('certificate_items.requires_fulfillment', true)
            ->get(['certificate_items.id', 'certificate_items.certificate_id', 'certificate_items.provider_id',
                'certificate_items.requires_scheduling', 'certificate_items.requires_confirmation']);
        foreach ($items as $item) {
            DB::table('service_fulfillments')->insert([
                'certificate_id' => $item->certificate_id, 'certificate_item_id' => $item->id, 'provider_id' => $item->provider_id,
                'status' => 'pending', 'requires_scheduling' => $item->requires_scheduling, 'requires_confirmation' => $item->requires_confirmation,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_fulfillments');
    }
};
