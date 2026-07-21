<?php

namespace App\Support;

use ArPHP\I18N\Arabic;

final class ArabicPdf
{
    public static function shapeHtml(string $html, string $locale): string
    {
        if ($locale !== 'ar') {
            return $html;
        }

        $arabic = new Arabic;

        return preg_replace_callback('/[\x{0600}-\x{06FF}][\x{0600}-\x{06FF}\x{0750}-\x{077F}\s]*/u', fn (array $match) => $arabic->utf8Glyphs(trim($match[0])).(str_ends_with($match[0], ' ') ? ' ' : ''), $html) ?? $html;
    }
}
