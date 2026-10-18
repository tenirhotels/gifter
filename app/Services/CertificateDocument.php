<?php

namespace App\Services;

use App\Enums\HistoryEvent;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * PDF сертификата для гостя: номер, получатель, пакет и состав (без цен),
 * срок действия, условия и QR-код на страницу проверки.
 */
class CertificateDocument
{
    public function __construct(private readonly CertificateHistoryRecorder $history) {}

    public function verificationUrl(Certificate $certificate): string
    {
        return route('certificates.verify', ['token' => $certificate->verification_token]);
    }

    public function qrDataUri(string $content): string
    {
        // SVG, а не PNG: не нужно расширение gd, и QR остаётся чётким при печати.
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'svgAddXmlHeader' => true,
            'connectPaths' => true,
            'drawLightModules' => false,
            'quietzoneSize' => 2,
        ]);

        return (new QRCode($options))->render($content);
    }

    public function pdf(Certificate $certificate): string
    {
        if (! $certificate->isPrintable()) {
            throw new DomainRuleViolation('PDF доступен только для выпущенного действующего сертификата.');
        }
        $certificate->loadMissing(['recipient', 'package', 'items']);
        $url = $this->verificationUrl($certificate);

        return Pdf::loadView('certificates.pdf', [
            'pages' => [['certificate' => $certificate, 'qr' => $this->qrDataUri($url), 'verifyUrl' => $url]],
            'hotel' => config('gifter.certificate'),
        ])->setPaper('a4')->output();
    }

    /** Один PDF на весь блок: по странице на сертификат. */
    public function batchPdf(CertificateBatch $batch): string
    {
        $certificates = $batch->certificates()->with(['recipient', 'package', 'items'])->get()->filter->isPrintable()->values();
        if ($certificates->isEmpty()) {
            throw new DomainRuleViolation('В блоке нет выпущенных действующих сертификатов.');
        }
        $pages = $certificates->map(fn (Certificate $certificate) => [
            'certificate' => $certificate,
            'qr' => $this->qrDataUri($this->verificationUrl($certificate)),
            'verifyUrl' => $this->verificationUrl($certificate),
        ]);
        foreach ($certificates as $certificate) {
            $this->history->record($certificate, HistoryEvent::DocumentDownloaded, "В составе блока №{$batch->id}");
        }

        return Pdf::loadView('certificates.pdf', ['pages' => $pages, 'hotel' => config('gifter.certificate')])->setPaper('a4')->output();
    }

    /** Скачивание фиксируется в истории: видно, кто и когда выдал документ. */
    public function download(Certificate $certificate): string
    {
        $pdf = $this->pdf($certificate);
        $this->history->record($certificate, HistoryEvent::DocumentDownloaded);

        return $pdf;
    }

    public function filename(Certificate $certificate): string
    {
        return "certificate-{$certificate->number}.pdf";
    }
}
