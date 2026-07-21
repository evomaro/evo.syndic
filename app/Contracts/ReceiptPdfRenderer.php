<?php

namespace App\Contracts;

interface ReceiptPdfRenderer
{
    public function render(string $html, string $locale): string;
}
