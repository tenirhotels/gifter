<?php

use App\Support\ContactHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Защита данных на shared-хостинге:
 * — контакты заказчиков и получателей хранятся зашифрованными (ключ APP_KEY);
 * — поиск по телефону и email — по HMAC-хешу на отдельном ключе CONTACT_HASH_KEY;
 * — двухфакторный вход: секрет приложения и коды восстановления (тоже зашифрованы).
 */
return new class extends Migration
{
    private const TABLES = ['customers', 'recipients'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex("{$name}_phone_index");
                if ($name === 'customers') {
                    $table->dropIndex('customers_email_index');
                }
            });
            Schema::table($name, function (Blueprint $table) {
                $table->text('phone')->nullable()->change();
                $table->text('email')->nullable()->change();
                $table->char('phone_hash', 64)->nullable()->index();
                $table->char('email_hash', 64)->nullable()->index();
            });

            DB::table($name)->orderBy('id')->each(function (object $row) use ($name) {
                DB::table($name)->where('id', $row->id)->update([
                    'phone' => $this->encrypt($row->phone),
                    'email' => $this->encrypt($row->email),
                    'notes' => $this->encrypt($row->notes),
                    'phone_hash' => ContactHash::phone($row->phone),
                    'email_hash' => ContactHash::email($row->email),
                ]);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            DB::table($name)->orderBy('id')->each(function (object $row) use ($name) {
                DB::table($name)->where('id', $row->id)->update([
                    'phone' => $this->decrypt($row->phone),
                    'email' => $this->decrypt($row->email),
                    'notes' => $this->decrypt($row->notes),
                ]);
            });
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex("{$name}_phone_hash_index");
                $table->dropIndex("{$name}_email_hash_index");
                $table->dropColumn(['phone_hash', 'email_hash']);
            });
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('phone', 32)->nullable()->index()->change();
                $table->string('email')->nullable()->change();
                if ($name === 'customers') {
                    $table->index('email');
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }

    private function encrypt(?string $value): ?string
    {
        return blank($value) ? null : Crypt::encryptString($value);
    }

    private function decrypt(?string $value): ?string
    {
        return blank($value) ? null : Crypt::decryptString($value);
    }
};
