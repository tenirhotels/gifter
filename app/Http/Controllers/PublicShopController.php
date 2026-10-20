<?php

namespace App\Http\Controllers;

use App\Mail\OnlineOrderMail;
use App\Mail\OnlineOrderReceivedMail;
use App\Models\OnlineOrder;
use App\Models\Package;
use App\Services\CertificateManager;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        $this->applyLang($request);

        $offers = Package::query()->where('is_active', true)->where('is_complimentary', false)->where('sold_online', true)
            ->with('items.service')->orderBy('name')->get()
            ->map(fn (Package $package) => $this->offer($package))->filter()->values();

        return view('shop.index', ['offers' => $offers]);
    }

    public function order(Request $request, Package $package): View
    {
        $this->applyLang($request);
        $offer = $this->offer($this->sellable($package));

        abort_if($offer === null, 404);

        return view('shop.order', ['offer' => $offer]);
    }

    public function store(Request $request, Package $package): RedirectResponse
    {
        $this->applyLang($request);
        $offer = $this->offer($this->sellable($package));
        abort_if($offer === null, 404);

        // Приманка для ботов: человек это поле не видит и не заполняет.
        if (filled($request->input('website'))) {
            return redirect()->route('shop.thanks');
        }

        $data = $request->validate([
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32', fn ($attribute, $value, $fail) => PhoneNumber::isValid($value) && PhoneNumber::normalize($value) !== null ? null : $fail(__(PhoneNumber::HINT).'.')],
            'email' => ['nullable', 'email', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ]);

        $order = OnlineOrder::create([
            'package_id' => $package->id,
            'last_name' => trim($data['last_name']), 'first_name' => trim($data['first_name']),
            'phone' => $data['phone'], 'email' => $data['email'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? null, 'notes' => $data['notes'] ?? null,
            'locale' => app()->getLocale(), 'ip_address' => $request->ip(),
        ]);

        $this->notify($order, $package);

        return redirect()->route('shop.thanks');
    }

    public function thanks(Request $request): View
    {
        $this->applyLang($request);

        return view('shop.thanks');
    }

    /** Язык страницы: ?lang=kk|en|ru запоминается в сессии. */
    private function applyLang(Request $request): void
    {
        $lang = (string) $request->query('lang');
        if (array_key_exists($lang, config('gifter.locales'))) {
            $request->session()->put('locale', $lang);
            app()->setLocale($lang);
        }
    }

    private function sellable(Package $package): ?Package
    {
        return $package->is_active && ! $package->is_complimentary && $package->sold_online ? $package : null;
    }

    /** @return array{package: Package, price: string, lines: list<array<string, mixed>>, validity: string}|null  Пакет без цены на сегодня не продаётся. */
    private function offer(?Package $package): ?array
    {
        if ($package === null) {
            return null;
        }
        $quote = $this->certificates->quote($package, [], today());
        if ($quote['missing'] !== [] || $quote['lines'] === [] || (float) $quote['total'] <= 0) {
            return null;
        }

        $validity = app()->getLocale() === 'ru'
            ? $package->validityLabel()
            : $package->validity_value.' '.$package->validity_unit->getLabel().' '.__('с даты продажи');

        return ['package' => $package, 'price' => $quote['total'], 'lines' => $quote['lines'], 'validity' => $validity];
    }

    /** Письма не должны терять заявку: сбой почты только пишется в журнал, без контактов клиента. */
    private function notify(OnlineOrder $order, Package $package): void
    {
        try {
            if ($to = config('gifter.online.mail_to')) {
                Mail::to($to)->send(new OnlineOrderMail($order->id, $package->name));
            }
            if (filled($order->email)) {
                Mail::to($order->email)->send(new OnlineOrderReceivedMail($package->name, $order->locale));
            }
        } catch (\Throwable $e) {
            Log::warning('Онлайн-заявка: письмо не отправлено', ['order' => $order->id, 'error' => $e::class]);
        }
    }
}
