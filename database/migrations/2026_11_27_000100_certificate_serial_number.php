<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', fn (Blueprint $t) => $t->unsignedBigInteger('serial_number')->nullable()->unique());

        // Уже созданные сертификаты нумеруются по порядку создания.
        $serial = 0;
        DB::table('certificates')->orderBy('id')->pluck('id')->each(function ($id) use (&$serial) {
            DB::table('certificates')->where('id', $id)->update(['serial_number' => ++$serial]);
        });
        if ($serial > 0) {
            DB::table('certificate_number_sequences')->insertOrIgnore(['scope' => 'serial', 'last_value' => 0]);
            DB::table('certificate_number_sequences')->where('scope', 'serial')->update(['last_value' => $serial]);
        }
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $t) => $t->dropColumn('serial_number'));
    }
};
