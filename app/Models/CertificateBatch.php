<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\CertificateKind;
use App\Enums\ComplimentaryPurpose;
use App\Enums\PaymentStatus;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Группа сертификатов: несколько сертификатов одного пакета, оформленных вместе.
 * Платная — один счёт на всю сумму, разложенный по сертификатам; бесплатная —
 * согласование директором. Черновики оформляются, оплачиваются и выпускаются
 * всей группой; после выпуска каждый сертификат живёт отдельно.
 */
class CertificateBatch extends Model
{
    use PreventsDeletion;

    protected $fillable = [
        'package_id', 'kind', 'customer_id', 'quantity', 'purpose', 'reason', 'valid_from', 'valid_until', 'status',
        'created_by', 'approved_by', 'approved_at', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'kind' => CertificateKind::class,
            'purpose' => ComplimentaryPurpose::class,
            'quantity' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'batch_id')->orderBy('id');
    }

    public function isComplimentary(): bool
    {
        return $this->kind === CertificateKind::Complimentary;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'batch_id');
    }

    public const TERMS = ['nom' => 'группа', 'acc' => 'группу', 'gen' => 'группы'];

    /** «группа» в нужном падеже — для сообщений и подписей. */
    public function term(string $case = 'nom', bool $capital = false): string
    {
        $term = self::TERMS[$case];

        return $capital ? mb_strtoupper(mb_substr($term, 0, 1)).mb_substr($term, 1) : $term;
    }

    /** «Платная» / «Бесплатная». */
    public function kindLabel(): string
    {
        return $this->isComplimentary() ? 'Бесплатная' : 'Платная';
    }

    /** «Группа №4». */
    public function shortLabel(): string
    {
        return 'Группа №'.$this->id;
    }

    /** «Группа №4 · Романтический уикенд × 10» (бесплатная — с целью). */
    public function label(): string
    {
        return "{$this->shortLabel()} · {$this->package?->name} × {$this->quantity}"
            .($this->isComplimentary() && $this->purpose ? " · {$this->purpose->getLabel()}" : '');
    }

    public function statusLabel(): string
    {
        return $this->status->labelFor($this->kind);
    }

    /** Бесплатные группы, которые ждут решения директора. */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', BatchStatus::Draft)->where('kind', CertificateKind::Complimentary);
    }

    /** Можно выпускать: бесплатная согласована, платная часть (у бесплатной — доп. услуги) оплачена по каждому сертификату. */
    public function scopeReadyToIssue(Builder $query): Builder
    {
        $confirmed = config('gifter.require_payment_reconciled') ? ' and p.reconciled_at is not null' : '';
        $unpaid = 'not exists (select 1 from certificates c where c.batch_id = certificate_batches.id and c.payable_amount >'
            .' (select coalesce(sum(amount), 0) from payments p where p.certificate_id = c.id and p.status = ?'.$confirmed.'))';

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('status', BatchStatus::Approved)->where('kind', CertificateKind::Complimentary)
                ->whereRaw($unpaid, [PaymentStatus::Paid->value]))
            ->orWhere(fn (Builder $q) => $q->where('status', BatchStatus::Draft)->where('kind', CertificateKind::Sale)
                ->whereHas('payments', fn (Builder $q) => $q->where('status', PaymentStatus::Paid))
                ->whereRaw($unpaid, [PaymentStatus::Paid->value])));
    }
}
