<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Mail\OnlineOrderMail;
use App\Mail\OnlineOrderReceivedMail;
use App\Models\OnlineOrder;
use App\Models\Package;
use App\Models\ShopSetting;
use App\Services\CertificateManager;
use App\Services\PackageSales;
use App\Support\KazakhstanBin;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Публичная страница «/»: витрина платных пакетов, помеченных «Продаётся онлайн», и форма заявки.
 * Оплаты и выпуска здесь нет — заявку оформляет сотрудник (OnlineOrderManager), счёт выставляется Kaspi/Halyk.
 */
class PublicShopController extends Controller
{
    public function __construct(private readonly CertificateManager $certificates) {}

    public function index(Request $request): View
    {
        $offers = $this->offers();

        return view('shop.index', ['offers' => $offers, 'shop' => ShopSetting::current(), 'termNote' => $this->termNote($offers)]);
    }

    /** Пакеты витрины с ценой на сегодня: их показывает «/» и перечисляет sitemap.xml. */
    public function offers(): Collection
    {
        return Package::query()->where('is_active', true)->where('is_complimentary', false)->where('sold_online', true)
            ->with('items.service')->orderByRaw('shop_position is null')->orderBy('shop_position')->orderBy('name')->get()
            ->reject(fn (Package $package) => $package->periodEnded())
            ->map(fn (Package $package) => $this->offer($package))->filter()->values();
    }

    public function order(Request $request, Package $package): View
    {
        $offer = $this->offer($this->sellable($package));

        abort_if($offer === null, 404);

        return view('shop.order', ['offer' => $offer, 'shop' => ShopSetting::current()]);
    }

    public function store(Request $request, Package $package): RedirectResponse
    {
        $offer = $this->offer($this->sellable($package));
        abort_if($offer === null, 404);
        if ($offer['sale'] !== PackageSales::OPEN) {
            return redirect()->route('shop.order', $package)->with('sale_closed', true);
        }

        // Приманка для ботов: человек это поле не видит и не заполняет.
        if (filled($request->input('website'))) {
            return redirect()->route('shop.thanks');
        }

        $request->merge(['bin' => preg_replace('/\D/', '', (string) $request->input('bin'))]);
        $shop = ShopSetting::current();
        $corporate = $shop->corporateEnabled() && $request->boolean('is_corporate');

        $data = $request->validate([
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32', fn ($attribute, $value, $fail) => PhoneNumber::isValid($value) && PhoneNumber::normalize($value) !== null ? null : $fail(__(PhoneNumber::HINT).'.')],
            'email' => ['nullable', 'required_if:delivery_method,pdf', 'email', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'gift_message' => ['nullable', 'string', 'max:500'],
            'is_corporate' => ['nullable', 'boolean'],
            'company' => [Rule::requiredIf($corporate), 'nullable', 'string', 'max:255'],
            'bin' => [Rule::requiredIf($corporate), 'nullable', 'string', 'size:12', fn ($attribute, $value, $fail) => blank($value) || KazakhstanBin::isValid($value) ? null : $fail(__('БИН введён с ошибкой: проверьте 12 цифр.'))],
            'delivery_method' => ['required', Rule::in(array_map(fn (DeliveryMethod $m) => $m->value, ShopSetting::current()->deliveryMethods()))],
            'delivery_address' => ['nullable', 'required_if:delivery_method,courier', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ]);

        // Лимиты занимаются атомарно ДО создания заявки (параллельные запросы не проскакивают между проверкой и записью);
        // при отказе или сбое созданной заявки занятые слоты возвращаются.
        $taken = [];
        foreach ($this->limits($request, $data) as [$key, $max, $seconds]) {
            $taken[] = $key;
            if (RateLimiter::increment($key, $seconds) > $max) {
                foreach ($taken as $used) {
                    RateLimiter::decrement($used);
                }
                throw ValidationException::withMessages(['phone' => __('Слишком много заявок. Попробуйте позже или свяжитесь с отелем.')]);
            }
        }

        try {
            $order = OnlineOrder::create([
                'package_id' => $package->id,
                'last_name' => trim($data['last_name']), 'first_name' => trim($data['first_name']),
                'phone' => $data['phone'], 'email' => $data['email'] ?? null,
                'recipient_name' => $shop->showsRecipient() ? ($data['recipient_name'] ?? null) : null,
                'gift_message' => $shop->showsRecipient() ? ($data['gift_message'] ?? null) : null,
                'is_corporate' => $corporate, 'company' => $corporate ? trim($data['company']) : null, 'bin' => $corporate ? $data['bin'] : null,
                'notes' => $data['notes'] ?? null,
                'delivery_method' => $data['delivery_method'],
                'delivery_address' => $data['delivery_method'] === DeliveryMethod::Courier->value ? $data['delivery_address'] : null,
                'locale' => app()->getLocale(), 'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            foreach ($taken as $used) {
                RateLimiter::decrement($used);
            }
            throw $e;
        }

        $this->notify($order, $package);

        return redirect()->route('shop.thanks');
    }

    public function thanks(Request $request): View
    {
        return view('shop.thanks', ['shop' => ShopSetting::current()]);
    }

    public function terms(Request $request): View
    {
        return view('shop.terms', ['shop' => ShopSetting::current()]);
    }

    public function privacy(Request $request): View
    {
        return view('shop.privacy', ['shop' => ShopSetting::current()]);
    }

    public function faq(Request $request): View
    {
        return view('shop.faq', ['shop' => ShopSetting::current()]);
    }

    /** «Срок действия — 60 дней»: только если у всех показанных сертификатов срок одинаков. */
    private function termNote(Collection $offers): ?string
    {
        $terms = $offers->map(fn (array $o) => $o['package']->isFixedPeriod() ? 'period' : $o['package']->validity_value.'|'.$o['package']->validity_unit->value)->unique();
        if ($terms->count() !== 1 || $terms->first() === 'period') {
            return null;
        }
        $package = $offers->first()['package'];
        $term = app()->getLocale() === 'ru'
            ? $package->validity_unit->format($package->validity_value)
            : $package->validity_value.' '.$package->validity_unit->getLabel();

        return __('Срок действия — :p1', ['p1' => $term]);
    }

    /**
     * Ограничения заявок, не зависящие от подмены IP: по адресу почты (3 в час), телефону (5 в сутки) и IP (20 в сутки).
     * Защита от рассылки писем на чужие адреса и засорения базы.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function limits(Request $request, array $data): array
    {
        $limits = [
            ['shop-ip:'.$request->ip(), 20, 86400],
            ['shop-phone:'.sha1((string) PhoneNumber::normalize($data['phone'])), 5, 86400],
        ];
        if (filled($data['email'] ?? null)) {
            $limits[] = ['shop-email:'.sha1(Str::lower(trim($data['email']))), 3, 3600];
        }

        return $limits;
    }

    private function sellable(Package $package): ?Package
    {
        return $package->is_active && ! $package->is_complimentary && $package->sold_online && ! $package->periodEnded() ? $package : null;
    }

    /** @return array{package: Package, price: string, lines: list<array<string, mixed>>, validity: string, sale: string, sale_note: ?string}|null  Пакет без цены на сегодня не продаётся. */
    private function offer(?Package $package): ?array
    {
        if ($package === null) {
            return null;
        }
        // Расчёт цены — несколько запросов на пакет: на минуту кешируем, цена всё равно пересчитывается при оформлении.
        $quote = Cache::remember('shop.quote.'.app()->getLocale().'.'.$package->id.'.'.today()->toDateString().'.'.$package->updated_at?->timestamp, 60, fn () => $this->certificates->quote($package, [], today()));
        if ($quote['missing'] !== [] || $quote['lines'] === [] || (float) $quote['total'] <= 0) {
            return null;
        }

        $validity = $package->validityText();

        return ['package' => $package, 'price' => $quote['total'], 'lines' => $quote['lines'], 'validity' => $validity, 'sale' => ($sale = app(PackageSales::class)->state($package)), 'sale_note' => match ($sale) {
            PackageSales::NOT_STARTED => __('Продажа начнётся :p1', ['p1' => $package->sales_from->format('d.m.Y')]),
            PackageSales::ENDED, PackageSales::SOLD_OUT => __('Продажа завершена'),
            default => null,
        }];
    }

    /** Письма не должны терять заявку: сбой почты только пишется в журнал, без контактов клиента. */
    private function notify(OnlineOrder $order, Package $package): void
    {
        try {
            if ($to = config('gifter.online.mail_to')) {
                Mail::to($to)->send(new OnlineOrderMail($order->id, $package->name));
            }
            if (filled($order->email) && RateLimiter::attempt('shop-mail:'.sha1(Str::lower($order->email)), 1, fn () => true, 3600)) {
                Mail::to($order->email)->send(new OnlineOrderReceivedMail($package->localizedName($order->locale), $order->locale));
            }
        } catch (\Throwable $e) {
            Log::warning('Онлайн-заявка: письмо не отправлено', ['order' => $order->id, 'error' => $e::class]);
        }
    }
}
