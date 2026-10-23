<?php

namespace App\Support;

use App\Enums\ValidityMode;
use App\Enums\ValidityUnit;
use App\Exceptions\DomainRuleViolation;
use App\Models\Package;
use Illuminate\Support\Carbon;

/**
 * Срок бесплатного сертификата, выбранный при выпуске:
 * по пакету (с даты продажи), N дней/месяцев с выбранной даты или до конкретного дня.
 * Для платных пакетов не применяется — их срок задаёт только пакет.
 */
final class ValidityChoice
{
    public function __construct(
        public readonly ValidityMode $mode,
        public readonly ?Carbon $from = null,
        public readonly ?int $value = null,
        public readonly ?ValidityUnit $unit = null,
        public readonly ?Carbon $until = null,
    ) {}

    /** @param  array{mode?:ValidityMode|string|null, from?:mixed, value?:mixed, unit?:ValidityUnit|string|null, until?:mixed}|null  $data */
    public static function fromArray(?array $data): self
    {
        $mode = $data['mode'] ?? ValidityMode::Package;
        $mode = $mode instanceof ValidityMode ? $mode : (ValidityMode::tryFrom((string) $mode) ?? ValidityMode::Package);
        $unit = $data['unit'] ?? null;
        $unit = $unit instanceof ValidityUnit ? $unit : ValidityUnit::tryFrom((string) $unit);

        return new self(
            $mode,
            filled($data['from'] ?? null) ? Carbon::parse($data['from'])->startOfDay() : null,
            filled($data['value'] ?? null) ? (int) $data['value'] : null,
            $unit,
            filled($data['until'] ?? null) ? Carbon::parse($data['until'])->startOfDay() : null,
        );
    }

    public function isCustom(): bool
    {
        return $this->mode !== ValidityMode::Package;
    }

    /** @return array{0: Carbon, 1: Carbon} [действует с, действует по] */
    public function resolve(Package $package, Carbon $soldAt): array
    {
        $soldAt = $soldAt->copy()->startOfDay();
        if (! $this->isCustom()) {
            return $package->validityRange($soldAt);
        }
        if (! $package->is_complimentary) {
            throw new DomainRuleViolation(__('Срок платного сертификата задаёт пакет — менять его при продаже нельзя.'));
        }

        $from = $this->from ?? $soldAt;
        if ($from->lt($soldAt)) {
            throw new DomainRuleViolation(__('Срок не может начинаться раньше даты выпуска.'));
        }

        if ($this->mode === ValidityMode::Duration) {
            if (! $this->unit || ! $this->value || $this->value < 1 || $this->value > 1000) {
                throw new DomainRuleViolation(__('Укажите длительность: от 1 до 1000 дней или месяцев.'));
            }
            $until = $this->unit->validUntil($from, $this->value);
        } else {
            if (! $this->until) {
                throw new DomainRuleViolation(__('Укажите последний день действия.'));
            }
            $until = $this->until;
        }

        if ($until->lt($from)) {
            throw new DomainRuleViolation(__('Последний день действия раньше даты начала.'));
        }
        if ($until->lt(today())) {
            throw new DomainRuleViolation(__('Последний день действия уже прошёл.'));
        }

        return [$from, $until];
    }

    /** «с 01.11.2026 по 30.11.2026, срок задан вручную» — для истории. */
    public function describe(Carbon $from, Carbon $until): string
    {
        return 'с '.$from->format('d.m.Y').' по '.$until->format('d.m.Y').($this->isCustom() ? __(', срок задан вручную') : '');
    }
}
