<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `asset_data_uri(relativePath)` function for Dompdf which cannot
 * resolve relative URLs.
 *
 * For SVG inputs, we look for a pre-rasterized PNG sibling
 * (foo.png in the same dir) since Dompdf's SVG bridge is unreliable.
 * Falls back to the raw SVG if no PNG is found.
 */
class SvgInlineExtension extends AbstractExtension
{
    public function __construct(
        private string $projectDir = '/app',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset_data_uri', $this->assetDataUri(...)),
            new TwigFunction('svg_data_uri', $this->assetDataUri(...)),
        ];
    }

    public function assetDataUri(string $relativePath): string
    {
        $path = $this->projectDir . '/public/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            return '';
        }
        $content = @file_get_contents($path);
        if (false === $content) {
            return '';
        }
        $mime = $this->guessMime($path);

        // For SVG, prefer pre-rasterized PNG sibling if available
        if ('image/svg+xml' === $mime) {
            $pngPath = preg_replace('/\.svg$/i', '', $path) . '.png';
            $pngInPngDir = dirname($path) . '/png/' . basename($pngPath);
            if (is_file($pngInPngDir)) {
                $path = $pngInPngDir;
                $content = @file_get_contents($path);
                $mime = 'image/png';
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
