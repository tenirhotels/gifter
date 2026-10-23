<?php

namespace Tests\Feature;

use App\Models\CertificateItem;
use App\Models\PmsService;
use App\Models\PmsSetting;
use App\Models\Service;
use App\Services\Pms\PmsCatalogSync;
use App\Services\Pms\PmsException;
use App\Support\LocalizedName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PmsSetting::create(['enabled' => true, 'provider' => 'exely', 'property_code' => '9305', 'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret']);
        Cache::flush();
    }

    /** @param  array<string, string>  $names  язык → название; null — Exely язык не учитывает */
    private function fakeExely(?array $names, array $failLangs = []): void
    {
        Http::fake(function ($request) use ($names, $failLangs) {
            if (str_contains($request->url(), '/auth/token')) {
                return Http::response(['access_token' => 'fictional-token', 'expires_in' => 900]);
            }
            $lang = $request->header('Accept-Language')[0] ?? 'ru';
            if (in_array($lang, $failLangs, true)) {
                return Http::response([], 500);
            }

            return Http::response(['id' => '9305', 'services' => [
                ['id' => '30', 'name' => $names === null ? 'Dinner à la carte' : $names[$lang], 'kind' => 'Meal', 'status' => 'Active'],
            ]]);
        });
    }

    public function test_sync_loads_names_in_each_language(): void
    {
        $this->fakeExely(['kk' => 'Ужин а ля карт (kk)', 'ru' => 'Ужин а-ля-карт', 'en' => 'Dinner à la carte']);

        $stats = app(PmsCatalogSync::class)->run();

        $pms = PmsService::firstOrFail();
        $this->assertSame(1, $stats['translated']);
        $this->assertSame('Ужин а-ля-карт', $pms->name);
        $this->assertEqualsCanonicalizing(['kk' => 'Ужин а ля карт (kk)', 'ru' => 'Ужин а-ля-карт', 'en' => 'Dinner à la carte'], $pms->names);
        $this->assertSame('Dinner à la carte', $pms->localizedName('en'));
        $this->assertSame('Ужин а-ля-карт', $pms->localizedName('ru'));
    }

    public function test_same_name_in_all_languages_means_no_translations(): void
    {
        $this->fakeExely(null);

        $stats = app(PmsCatalogSync::class)->run();

        $this->assertSame(0, $stats['translated']);
        $this->assertNull(PmsService::firstOrFail()->names);
        $this->assertStringContainsString('не отдаёт', PmsCatalogSync::languagesNote($stats));
    }

    public function test_extra_language_failure_does_not_break_sync(): void
    {
        $this->fakeExely(['kk' => 'x', 'ru' => 'Ужин', 'en' => 'Dinner'], failLangs: ['kk']);

        $stats = app(PmsCatalogSync::class)->run();

        $this->assertSame(1, $stats['total']);
        $this->assertEqualsCanonicalizing(['ru' => 'Ужин', 'en' => 'Dinner'], PmsService::firstOrFail()->names);
    }

    public function test_main_language_failure_fails_sync(): void
    {
        $this->fakeExely(['kk' => 'x', 'ru' => 'Ужин', 'en' => 'Dinner'], failLangs: ['ru']);

        $this->expectException(PmsException::class);
        app(PmsCatalogSync::class)->run();
    }

    public function test_dictionary_name_is_parsed(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), '/auth/token')
            ? Http::response(['access_token' => 't', 'expires_in' => 900])
            : Http::response(['services' => [['id' => '30', 'name' => ['ru-RU' => 'Ужин', 'en' => 'Dinner', 'kk' => 'Кешкі ас'], 'kind' => 'Meal', 'status' => 'Active']]]));

        app(PmsCatalogSync::class)->run();

        $this->assertEqualsCanonicalizing(['ru' => 'Ужин', 'en' => 'Dinner', 'kk' => 'Кешкі ас'], PmsService::firstOrFail()->names);
    }

    public function test_service_gets_translations_but_manual_edits_are_kept(): void
    {
        $this->fakeExely(['kk' => 'Кешкі ас', 'ru' => 'Ужин', 'en' => 'Dinner']);
        $sync = app(PmsCatalogSync::class);
        $sync->run();
        $service = Service::create(['name' => 'Ужин', 'unit' => 'шт', 'name_translations' => ['en' => 'Evening meal']]);
        $pms = PmsService::firstOrFail();
        $sync->link($pms, $service);

        $sync->run();

        $this->assertEqualsCanonicalizing(['en' => 'Evening meal', 'kk' => 'Кешкі ас'], $service->refresh()->name_translations);
    }

    public function test_enabling_pms_service_creates_service_with_translations(): void
    {
        $this->fakeExely(['kk' => 'Кешкі ас', 'ru' => 'Ужин', 'en' => 'Dinner']);
        $sync = app(PmsCatalogSync::class);
        $sync->run();

        $sync->setAllowed(PmsService::firstOrFail(), true);

        $service = Service::firstOrFail();
        $this->assertSame('Ужин', $service->name);
        $this->assertEqualsCanonicalizing(['kk' => 'Кешкі ас', 'en' => 'Dinner'], $service->name_translations);
        $this->assertSame('Кешкі ас', $service->localizedName('kk'));
    }

    public function test_fallback_chain_and_old_rows(): void
    {
        $this->assertSame('Ужин', LocalizedName::pick(['en' => 'Dinner'], 'Ужин', 'kk'));
        $this->assertSame('Dinner', LocalizedName::pick(['en' => 'Dinner', 'ru' => ''], 'Dinner', 'ru'));
        $this->assertSame('Dinner', LocalizedName::pick(['en' => 'Dinner'], '', 'kk'));
        $this->assertSame('Ужин', LocalizedName::pick(null, 'Ужин', 'en'));

        $old = new CertificateItem(['service_name_snapshot' => 'Старая услуга']);
        $this->assertSame('Старая услуга', $old->localizedName('kk'));

        $item = new CertificateItem(['service_name_snapshot' => 'Ужин', 'service_name_translations' => ['kk' => 'Кешкі ас', 'en' => 'Dinner']]);
        $this->assertSame('Кешкі ас', $item->localizedName('kk'));
        app()->setLocale('en');
        $this->assertSame('Dinner', $item->localizedName());
    }

    public function test_service_drops_empty_translations(): void
    {
        $service = Service::create(['name' => 'Ужин', 'unit' => 'шт', 'name_translations' => ['kk' => ' ', 'en' => null]]);

        $this->assertNull($service->refresh()->name_translations);
    }
}
