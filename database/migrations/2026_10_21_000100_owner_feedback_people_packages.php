<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Замечания владельца после теста (21.10.2026):
 * ФИО раздельно, покупатель-компания с БИН, получатель необязателен,
 * срок действия задаётся пакетом, новые типы поставщиков.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'recipients'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('last_name')->nullable()->after('id');
                $table->string('first_name')->nullable()->after('last_name');
                $table->string('middle_name')->nullable()->after('first_name');
            });
            // Существующее ФИО: первое слово — фамилия, остальное — имя.
            DB::table($table)->orderBy('id')->each(function ($row) use ($table) {
                $parts = preg_split('/\s+/u', trim($row->full_name), 2);
                DB::table($table)->where('id', $row->id)->update(['last_name' => $parts[0] ?? '', 'first_name' => $parts[1] ?? '']);
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('type', 20)->default('individual')->after('id');
            $table->char('bin', 12)->nullable()->after('company')->index();
        });
        DB::table('customers')->whereNotNull('company')->where('company', '!=', '')->update(['type' => 'company']);

        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('recipient_id')->nullable()->change();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('validity_value')->default(2)->after('description');
            $table->string('validity_unit', 10)->default('months')->after('validity_value');
        });

        Schema::table('certificate_items', function (Blueprint $table) {
            $table->boolean('from_package')->default(false)->after('position');
        });

        foreach (['hotel' => 'rooms', 'restaurant' => 'food_beverage', 'internal_department' => 'other_department', 'third_party' => 'external'] as $old => $new) {
            DB::table('providers')->where('type', $old)->update(['type' => $new]);
        }
    }

    public function down(): void
    {
        foreach (['rooms' => 'hotel', 'food_beverage' => 'restaurant', 'other_department' => 'internal_department', 'external' => 'third_party'] as $old => $new) {
            DB::table('providers')->where('type', $old)->update(['type' => $new]);
        }
        Schema::table('certificate_items', fn (Blueprint $table) => $table->dropColumn('from_package'));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn(['validity_value', 'validity_unit']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['type', 'bin']));
        foreach (['customers', 'recipients'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['last_name', 'first_name', 'middle_name']));
        }
    }
};
