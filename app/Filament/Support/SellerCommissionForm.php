<?php

namespace App\Filament\Support;

use App\Enums\CommissionType;
use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\User;
use App\Services\CommissionManager;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Collection;

/** Поля и выполнение действия «Продавец и комиссия» для выпущенных (в том числе перенесённых из таблицы) сертификатов. */
final class SellerCommissionForm
{
    public static function allowed(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can(Permission::AdminCorrections) || $user?->can(Permission::ApproveCommissions));
    }

    /** @return list<Component> */
    public static function schema(): array
    {
        return [
            Select::make('seller')->label('Продавец')->required()->searchable()->options(fn () => User::sellerOptions()),
            Select::make('type')->label('Комиссия')->required()->options(CommissionType::class)->default(CommissionType::Percent->value)->live(),
            TextInput::make('value')->label(fn ($get) => $get('type') === CommissionType::Fixed->value ? __('Сумма комиссии, ₸') : __('Процент от цены продажи'))
                ->required()->numeric()->minValue(0.01),
            Textarea::make('reason')->label('Причина')->required()->minLength(5)
                ->helperText(__('Например: «перенос из старой таблицы, продавец по договорённости». Останется в истории и журнале.')),
        ];
    }

    /** Select с enum отдаёт либо значение, либо сам enum. */
    private static function type(mixed $value): CommissionType
    {
        return $value instanceof CommissionType ? $value : CommissionType::from((string) $value);
    }

    /** Одиночное действие: ошибки правил показываются как уведомление. */
    public static function apply(Certificate $certificate, array $data): bool
    {
        try {
            app(CommissionManager::class)->assignSellerAndRecalculate(
                $certificate, User::findOrFail((int) $data['seller']), self::type($data['type']), (string) $data['value'], (string) $data['reason'],
            );
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }
        Notification::make()->success()->title(__('Продавец назначен, комиссия пересчитана'))->send();

        return true;
    }

    /** Массовое действие: выполненные считаются, у пропущенных называется причина. @param  Collection<int, Certificate>  $records */
    public static function applyMany(Collection $records, array $data): void
    {
        $manager = app(CommissionManager::class);
        $seller = User::findOrFail((int) $data['seller']);
        $done = 0;
        $errors = [];
        foreach ($records as $certificate) {
            try {
                $manager->assignSellerAndRecalculate($certificate, $seller, self::type($data['type']), (string) $data['value'], (string) $data['reason']);
                $done++;
            } catch (DomainRuleViolation $e) {
                $errors[] = $certificate->number.': '.$e->getMessage();
            }
        }
        $note = Notification::make()->title(__('Готово: :p1, пропущено: :p2', ['p1' => $done, 'p2' => count($errors)]));
        if ($errors) {
            $note->warning()->body(implode("\n", array_slice($errors, 0, 8)).(count($errors) > 8 ? "\n…" : ''))->persistent();
        } else {
            $note->success();
        }
        $note->send();
    }
}
