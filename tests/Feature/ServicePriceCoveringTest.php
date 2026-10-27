<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Models\User;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServicePriceCoveringTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->service = Service::factory()->create();
    }

    private function pricing(): ServicePricing
    {
        return app(ServicePricing::class);
    }

    public function test_new_price_inside_closed_period_shortens_current_one(): void
    {
        $old = $this->pricing()->addPrice($this->service, '214000', Carbon::parse('2026-10-23'), Carbon::parse('2026-11-30'));

        $new = $this->pricing()->addPrice($this->service, '174000', Carbon::parse('2026-10-27'), null, '100000');

        $this->assertSame('2026-10-26', $old->fresh()->valid_until->toDateString());
        $this->assertNull($new->valid_until);
        $this->assertSame(174000, (int) $this->pricing()->priceOn($this->service, Carbon::parse('2026-10-27'))->price);
        $this->assertSame(214000, (int) $this->pricing()->priceOn($this->service, Carbon::parse('2026-10-25'))->price);
    }

    public function test_past_start_inside_closed_period_is_rejected(): void
    {
        $this->pricing()->addPrice($this->service, '214000', Carbon::parse('2026-10-20'), Carbon::parse('2026-11-30'));

        $this->expectException(DomainRuleViolation::class);
        $this->pricing()->addPrice($this->service, '174000', Carbon::parse('2026-10-25'));
    }

    public function test_end_before_current_period_end_is_rejected_and_nothing_changes(): void
    {
        $old = $this->pricing()->addPrice($this->service, '214000', Carbon::parse('2026-10-23'), Carbon::parse('2026-11-30'));

        try {
            $this->pricing()->addPrice($this->service, '174000', Carbon::parse('2026-10-27'), Carbon::parse('2026-11-10'));
            $this->fail('Ожидалась ошибка');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('30.11.2026', $e->getMessage());
        }

        $this->assertSame('2026-11-30', $old->fresh()->valid_until->toDateString());
        $this->assertSame(1, $this->service->prices()->count());
    }

    public function test_open_ended_price_still_closes_as_before(): void
    {
        $old = $this->pricing()->addPrice($this->service, '100000', Carbon::parse('2026-01-01'));
        $this->pricing()->addPrice($this->service, '120000', Carbon::parse('2026-10-27'));

        $this->assertSame('2026-10-26', $old->fresh()->valid_until->toDateString());
    }

    public function test_director_price_waits_for_approval_then_shortens_current(): void
    {
        $old = $this->pricing()->addPrice($this->service, '214000', Carbon::parse('2026-10-23'), Carbon::parse('2026-11-30'));
        config(['gifter.require_price_approval' => true]);
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));

        $new = $this->pricing()->addPrice($this->service, '174000', Carbon::parse('2026-10-27'));

        $this->assertNull($new->approved_at);
        $this->assertSame('2026-11-30', $old->fresh()->valid_until->toDateString());

        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->pricing()->approve($new);

        $this->assertSame('2026-10-26', $old->fresh()->valid_until->toDateString());
        $this->assertNotNull($new->fresh()->approved_at);
    }
}
