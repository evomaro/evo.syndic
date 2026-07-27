<?php

namespace App\Services;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\Payment;
use App\Models\SupplierSettlement;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use RuntimeException;

class FinancialDocumentRenderer
{
    public function __construct(private ReceiptPdfRenderer $renderer) {}

    public function receipt(Payment $payment, string $number, string $locale, string $token): string
    {
        $url = route('receipts.verify', ['token' => $token]);
        $this->assertSecureProductionUrl($url);
        $qr = $this->qr($url);

        return $this->renderer->render(view('pdf.receipt', compact('payment', 'number', 'locale', 'qr'))->render(), $locale);
    }

    public function voucher(SupplierSettlement $settlement, string $number, string $locale, string $token): string
    {
        $url = route('financial-documents.verify', ['token' => $token]);
        $this->assertSecureProductionUrl($url);
        $qr = $this->qr($url);

        return $this->renderer->render(view('pdf.supplier-voucher', compact('settlement', 'number', 'locale', 'qr'))->render(), $locale);
    }

    private function qr(string $url): string
    {
        return (new PngWriter)->write(new QrCode(data: $url, size: 180, margin: 4))->getDataUri();
    }

    private function assertSecureProductionUrl(string $url): void
    {
        if (app()->environment('production') && ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Financial document verification URL must use HTTPS in production.');
        }
    }
}
