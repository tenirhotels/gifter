<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Замечания 24.10.2026: новые цели бесплатных сертификатов и срок,
 * выбираемый при выпуске (длительность с даты или конкретный последний день).
 */
return new class extends Migration
{
    private const MAP = ['blogger' => 'influencers', 'marketing' => 'other'];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('packages')->where('complimentary_purpose', $old)->update(['complimentary_purpose' => $new]);
            DB::table('certificates')->where('complimentary_purpose', $old)->update(['complimentary_purpose' => $new]);
            DB::table('certificate_batches')->where('purpose', $old)->update(['purpose' => $new]);
        }

        Schema::table('certificates', function (Blueprint $table) {
            $table->boolean('custom_validity')->default(false)->after('valid_until');
        });
        Schema::table('certificate_batches', function (Blueprint $table) {
            $table->date('valid_from')->nullable()->after('reason');
            $table->date('valid_until')->nullable()->after('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_batches', fn (Blueprint $table) => $table->dropColumn(['valid_from', 'valid_until']));
        Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn('custom_validity'));
        foreach (self::MAP as $old => $new) {
            DB::table('packages')->where('complimentary_purpose', $new)->update(['complimentary_purpose' => $old]);
            DB::table('certificates')->where('complimentary_purpose', $new)->update(['complimentary_purpose' => $old]);
            DB::table('certificate_batches')->where('purpose', $new)->update(['purpose' => $old]);
        }
    }
};
