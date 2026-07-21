<?php

namespace App\Services;

use App\Contracts\ReceiptPdfRenderer;
use App\Support\ArabicPdf;
use Barryvdh\DomPDF\Facade\Pdf;

class DompdfReceiptRenderer implements ReceiptPdfRenderer
{
    public function render(string $html, string $locale): string
    {
        return Pdf::loadHTML(ArabicPdf::shapeHtml($html, $locale))->setPaper('a4')->output();
    }
}
