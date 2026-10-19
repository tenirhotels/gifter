<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Защита клиентской базы от выгрузки «по одному»: не более N разных клиентов в день на сотрудника
 * (`gifter.security.reveal_daily_limit`). Администратор не ограничен. Превышение пишется в журнал и видно администратору.
 */
class ContactRevealGuard
{
    /** Можно ли показать контакты. Если можно — открытие записывается в журнал; если нет — фиксируется попытка. */
    public function reveal(Model $person): bool
    {
        $user = auth()->user();
        $today = AuditLog::query()->where('user_id', $user?->id)->where('event', 'contacts_revealed')->where('created_at', '>=', today());
        $limit = (int) config('gifter.security.reveal_daily_limit');

        $alreadySeen = (clone $today)->where('auditable_type', $person->getMorphClass())->where('auditable_id', $person->getKey())->exists();
        if ($limit > 0 && ! $user?->isSuperAdmin() && ! $alreadySeen
            && (clone $today)->distinct()->count(DB::raw("concat(auditable_type, '#', auditable_id)")) >= $limit) {
            AuditLog::record('contacts_reveal_limit', $person, null, ['limit' => $limit]);

            return false;
        }
        AuditLog::record('contacts_revealed', $person, null, null);

        return true;
    }
}
