<?php

namespace App\Service;

/**
 * Builds Dompdf instances configured to use a writable font cache
 * (Dompdf's default font dir is /app/vendor/dompdf/.../lib/fonts which
 * is not writable in our container).
 */
class DompdfFactory
{
    private string $fontDir;

    public function __construct(string $projectDir = '/app')
    {
        $this->fontDir = $projectDir . '/var/dompdf/fonts';

        if (!is_dir($this->fontDir)) {
            @mkdir($this->fontDir, 0775, true);
        }
    }

    public function create(): \Dompdf\Dompdf
    {
        $options = new \Dompdf\Options();
        $options->setFontDir($this->fontDir);
        $options->setFontCache($this->fontDir);
        // Keep HTML default to allow CSS-driven styling
        $options->setIsHtml5ParserEnabled(true);

        return new \Dompdf\Dompdf($options);
    }
}
