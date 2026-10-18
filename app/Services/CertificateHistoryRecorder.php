<?php

namespace App\Services;

use App\Enums\HistoryEvent;
use App\Models\Certificate;
use App\Models\CertificateHistory;

class CertificateHistoryRecorder
{
    public function record(Certificate $certificate, HistoryEvent $event, ?string $description = null, ?array $before = null, ?array $after = null): CertificateHistory
    {
        return $certificate->history()->create([
            'user_id' => auth()->id(),
            'event' => $event,
            'description' => $description,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
