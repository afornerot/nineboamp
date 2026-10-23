<?php

namespace App\Cache;

use Bnine\FilesBundle\Cache\CachePolicyInterface;

class FileCachePolicy implements CachePolicyInterface
{
    private const PUBLIC_DOMAINS = ['avatar', 'logo', 'icon'];
    private const PUBLIC_MAX_AGE = 2592000;

    public function getCacheMaxAge(string $domain, string $id, string $filePath): ?int
    {
        return in_array($domain, self::PUBLIC_DOMAINS, true) ? self::PUBLIC_MAX_AGE : null;
    }

    public function isPublicCacheable(string $domain, string $id, string $filePath): ?bool
    {
        return in_array($domain, self::PUBLIC_DOMAINS, true) ? true : null;
    }
}
