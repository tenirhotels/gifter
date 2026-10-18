<?php

namespace Tests;

use App\Models\Package;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Пакет без базового состава со сроком 12 месяцев — состав в тестах набирается доп. услугами. */
    protected function emptyPackageId(): int
    {
        return Package::firstOrCreate(['name' => 'Тестовый продукт'], ['validity_value' => 12, 'validity_unit' => 'months'])->id;
    }
}
