<?php

namespace App\Twig;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MarkdownExtension extends AbstractExtension
{
    private GithubFlavoredMarkdownConverter $converter;

    public function __construct()
    {
        $this->converter = new GithubFlavoredMarkdownConverter();
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('gfm_to_html', [$this, 'convert'], ['is_safe' => ['html']]),
        ];
    }

    public function convert(string $markdown): string
    {
        return $this->converter->convertToHtml($markdown);
    }
}
