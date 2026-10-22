<?php

namespace App\Support;

use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Enums\ComplimentaryPurpose;
use App\Enums\CustomerType;
use App\Enums\DeliveryMethod;
use App\Enums\ExceptionStatus;
use App\Enums\LegalForm;
use App\Enums\OnlineOrderStatus;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Models\AuditLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Журнал аудита «по-человечески»: события, объекты, поля и значения — понятными словами вместо технических имён.
 * Данные записи не меняются: это только показ. Скрытые значения («[скрыто]») остаются скрытыми.
 */
final class AuditPresenter
{
    /** Поля, которые в показе пропускаются: служебные и технические. */
    private const SKIP = ['id', 'created_at', 'updated_at', 'password', 'remember_token'];

    /** Поля-«да/нет». */
    private const BOOLEAN_PREFIXES = ['is_', 'requires_', 'allowed_', 'sold_'];

    /** Суммы. */
    private const MONEY = ['price', 'cost', 'amount', 'fixed_amount', 'total_amount'];

    public static function event(string $event): string
    {
        return [
            'created' => __('Создание'),
            'updated' => __('Изменение'),
            'deleted' => __('Удаление'),
            'shop_settings_changed' => __('Изменены настройки сайта'),
            'pms_settings_changed' => __('Изменены настройки PMS'),
            'seller_report_exported' => __('Выгрузка отчёта по продавцам'),
            'backup_downloaded' => __('Скачана резервная копия'),
            'two_factor_enabled' => __('Включён двухфакторный вход'),
            'two_factor_disabled' => __('Выключен двухфакторный вход'),
            'two_factor_reset' => __('Сброшен двухфакторный вход'),
            'contacts_revealed' => __('Открыты контакты клиента'),
            'contacts_reveal_limit' => __('Превышен дневной лимит открытия контактов'),
            'commission_rule_added' => __('Добавлено правило комиссии'),
            'commission_created' => __('Начислена комиссия'),
            'commission_payment_made' => __('Выплачена комиссия'),
            'commission_payment_reversed' => __('Выплата комиссии отменена'),
            'commission_approved' => __('Комиссия согласована'),
            'commission_unapproved' => __('Согласование комиссии снято'),
            'commission_reversed' => __('Комиссия отменена'),
            'reservation_exception_requested' => __('Запрошено исключение по броне'),
            'reservation_exception_approved' => __('Исключение по броне одобрено'),
            'reservation_exception_rejected' => __('Исключение по броне отклонено'),
            'sale_date_overridden' => __('Дата продажи изменена вручную'),
            'seller_overridden' => __('Продавец назначен вручную'),
        ][$event] ?? str_replace('_', ' ', $event);
    }

    /** Цвет значка события. */
    public static function eventColor(string $event): string
    {
        return match (true) {
            $event === 'created' => 'success',
            $event === 'updated' => 'warning',
            $event === 'deleted' || str_contains($event, 'reset') || str_contains($event, 'limit') || str_contains($event, 'rejected') || str_contains($event, 'reversed') => 'danger',
            default => 'gray',
        };
    }

    /** Название типа объекта. */
    public static function subjectType(string $morphClass): string
    {
        return [
            'User' => __('Пользователь'), 'Customer' => __('Заказчик'), 'Recipient' => __('Получатель'), 'Service' => __('Услуга'),
            'ServiceCategory' => __('Категория услуг'), 'ServicePrice' => __('Цена услуги'), 'Package' => __('Пакет'), 'Provider' => __('Поставщик'),
            'PmsService' => __('Услуга из PMS'), 'PmsSetting' => __('Настройки PMS'), 'ShopSetting' => __('Настройки сайта'), 'OnlineOrder' => __('Заявка с сайта'),
            'Certificate' => __('Сертификат'), 'CertificateBatch' => __('Группа сертификатов'), 'CertificateCommission' => __('Комиссия'),
            'CommissionRule' => __('Правило комиссии'), 'CommissionPayment' => __('Выплата комиссии'), 'ReservationException' => __('Исключение по броне'),
            'Payment' => __('Платёж'),
        ][class_basename($morphClass)] ?? class_basename($morphClass);
    }

    /** «Пользователь «Kamila»» или «Заявка с сайта № 12»: название берётся из записи журнала, а не из живых (зашифрованных) данных. */
    public static function subject(AuditLog $log): string
    {
        $type = self::subjectType((string) $log->auditable_type);
        $data = (array) ($log->after ?? $log->before ?? []);
        foreach (['name', 'hero_title'] as $key) {
            $name = Arr::get($data, $key);
            if (filled($name) && ! str_contains((string) $name, '[скрыто]')) {
                return $type.' «'.$name.'»';
            }
        }
        $number = data_get($log->auditable, 'number');

        return $type.' № '.($number ?: $log->auditable_id);
    }

    /**
     * Строки «поле — было — стало» для карточки записи.
     *
     * @return list<array{label: string, before: ?string, after: ?string}>
     */
    public static function rows(AuditLog $log): array
    {
        $before = self::clean((array) ($log->before ?? []));
        $after = self::clean((array) ($log->after ?? []));
        $keys = array_values(array_unique([...array_keys($after), ...array_keys($before)]));
        $rows = [];
        foreach ($keys as $key) {
            $old = array_key_exists($key, $before) ? self::value($log, $key, $before[$key]) : null;
            $new = array_key_exists($key, $after) ? self::value($log, $key, $after[$key]) : null;
            if ($log->event === 'created' && ($new === null || $new === '—')) {
                continue;
            }
            if ($old === $new && $old !== null) {
                continue;
            }
            $rows[] = ['label' => self::field($key), 'before' => $old, 'after' => $new];
        }

        return $rows;
    }

    /** Краткое описание изменений одной строкой (для списка). */
    public static function summary(AuditLog $log): string
    {
        $rows = self::rows($log);
        if ($rows === []) {
            return '';
        }
        $parts = collect($rows)->map(function (array $row) use ($log) {
            if ($log->event === 'created' || $row['before'] === null) {
                return $row['label'].': '.($row['after'] ?? '—');
            }
            if ($row['after'] === null) {
                return $row['label'].': '.$row['before'].' → '.__('удалено');
            }

            return $row['label'].': '.$row['before'].' → '.$row['after'];
        });

        return $parts->implode('; ');
    }

    public static function field(string $key): string
    {
        return [
            'name' => __('Имя'), 'email' => __('Email'), 'phone' => __('Телефон'), 'is_active' => __('Активен'), 'roles' => __('Роли'), 'locale' => __('Язык'),
            'type' => __('Тип'), 'kind' => __('Вид'), 'status' => __('Статус'), 'description' => __('Описание'), 'notes' => __('Заметки'), 'reason' => __('Причина'),
            'last_name' => __('Фамилия'), 'first_name' => __('Имя'), 'middle_name' => __('Отчество'), 'company' => __('Компания'), 'bin' => __('БИН'),
            'external_id' => __('Код в PMS'), 'synced_at' => __('Синхронизировано'), 'missing_since' => __('Пропала из PMS с'), 'allowed_in_certificate' => __('Можно в сертификат'),
            'service_id' => __('Услуга'), 'category_id' => __('Категория'), 'provider_id' => __('Поставщик'), 'package_id' => __('Пакет'), 'unit' => __('Единица'),
            'requires_fulfillment' => __('Требует исполнения'), 'requires_scheduling' => __('Требует согласования времени'), 'requires_confirmation' => __('Требует подтверждения'),
            'validity_value' => __('Срок действия'), 'validity_unit' => __('Единица срока'), 'is_complimentary' => __('Бесплатный'), 'complimentary_purpose' => __('Цель бесплатного'),
            'sold_online' => __('Продаётся онлайн'), 'image_path' => __('Фото'), 'price' => __('Цена'), 'cost' => __('Себестоимость'), 'currency' => __('Валюта'),
            'valid_from' => __('Действует с'), 'valid_until' => __('Действует по'), 'approved_at' => __('Подтверждено'), 'approved_by' => __('Кто подтвердил'),
            'created_by' => __('Кто создал'), 'rate' => __('Ставка, %'), 'fixed_amount' => __('Фиксированная сумма'), 'amount' => __('Сумма'), 'total_amount' => __('Общая сумма'),
            'legal_form' => __('Форма'), 'legal_name' => __('Юридическое название'), 'tax_id' => __('БИН / ИИН'), 'contact_person' => __('Контактное лицо'),
            'request_locale' => __('Язык заявок'), 'position' => __('Порядок'), 'hero_image_path' => __('Главное фото'), 'hero_title' => __('Заголовок'),
            'hero_subtitle' => __('Подзаголовок'), 'accent_color' => __('Акцентный цвет'), 'ga4_id' => __('Google Analytics'), 'metrika_id' => __('Яндекс.Метрика'),
            'whatsapp_phone' => __('WhatsApp'), 'operator_name' => __('Оператор данных'), 'offer_url' => __('Адрес оферты'), 'delivery_method' => __('Получение'),
            'delivery_address' => __('Адрес доставки'), 'gift_message' => __('Поздравление'), 'recipient_name' => __('Получатель'), 'certificate_id' => __('Сертификат'),
            'handled_by' => __('Кто обработал'), 'handled_at' => __('Когда обработано'), 'reject_reason' => __('Причина отказа'), 'reminded_at' => __('Напоминание отправлено'),
            'ip_address' => __('IP-адрес'), 'seller_id' => __('Продавец'), 'commission_id' => __('Комиссия'), 'file' => __('Файл'), 'limit' => __('Лимит'),
            'enabled' => __('Включено'), 'provider' => __('Провайдер'), 'base_url' => __('Адрес'), 'property_code' => __('Код отеля'), 'booking_number' => __('Номер брони'),
            'updated_by' => __('Кто изменил'), 'token' => __('Токен'), 'client_id' => __('Client ID'), 'client_secret' => __('Client secret'),
        ][$key] ?? $key;
    }

    /** @param  array<string, mixed>  $data */
    private static function clean(array $data): array
    {
        $data = array_diff_key($data, array_flip(self::SKIP));

        return array_filter($data, fn ($value, $key) => ! str_ends_with((string) $key, '_hash'), ARRAY_FILTER_USE_BOTH);
    }

    private static function value(AuditLog $log, string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_string($value) && str_contains($value, '[скрыто]')) {
            return __('скрыто');
        }
        if (array_key_exists($key, self::enums()[class_basename((string) $log->auditable_type)] ?? [])) {
            return self::enumValue(self::enums()[class_basename((string) $log->auditable_type)][$key], $value);
        }
        foreach (self::BOOLEAN_PREFIXES as $prefix) {
            if ((str_starts_with($key, $prefix) || $key === 'enabled') && in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                return in_array($value, [true, 1, '1'], true) ? __('да') : __('нет');
            }
        }
        if (in_array($key, self::MONEY, true) && is_numeric($value)) {
            return Money::format($value);
        }
        if (is_bool($value)) {
            return $value ? __('да') : __('нет');
        }
        if (is_array($value)) {
            return collect($value)->map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE))->implode(', ');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
            return Carbon::parse($value)->format('d.m.Y H:i');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value)->format('d.m.Y');
        }

        return (string) $value;
    }

    /** @return array<string, array<string, class-string>> Поля со списком допустимых значений (по типу объекта). */
    private static function enums(): array
    {
        return [
            'User' => ['roles' => Role::class],
            'OnlineOrder' => ['status' => OnlineOrderStatus::class, 'delivery_method' => DeliveryMethod::class],
            'CertificateCommission' => ['status' => CommissionStatus::class],
            'CommissionRule' => ['type' => CommissionType::class],
            'Customer' => ['type' => CustomerType::class],
            'Provider' => ['type' => ProviderType::class, 'legal_form' => LegalForm::class],
            'Package' => ['complimentary_purpose' => ComplimentaryPurpose::class],
            'ReservationException' => ['status' => ExceptionStatus::class],
        ];
    }

    private static function enumValue(string $enum, mixed $value): string
    {
        $values = is_array($value) ? $value : (is_string($value) && str_starts_with($value, '[') ? (array) json_decode($value, true) : [$value]);

        return collect($values)->map(function ($item) use ($enum) {
            $case = $enum::tryFrom((string) $item);

            return $case && method_exists($case, 'getLabel') ? (string) $case->getLabel() : (string) $item;
        })->implode(', ');
    }
}
