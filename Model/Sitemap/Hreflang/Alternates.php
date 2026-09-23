<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Hreflang;

/**
 * The hreflang alternates of one sitemap item, as filed in its data bag.
 *
 * The full set for the URL — region codes, language-only codes, x-default — exactly as the page's
 * head declares it, observers of `mageos_seo_hreflang_alternates_after` included. Each entry is
 * `{hreflang, url}` as this module builds it; an observer may have rewritten the list, so a reader
 * should not assume more than an array per entry.
 */
class Alternates
{
    /**
     * @param array<int,array<string,mixed>> $alternates
     */
    public function __construct(
        private readonly array $alternates
    ) {
    }

    /**
     * Every alternate, in the order the head declares them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAlternates(): array
    {
        return $this->alternates;
    }
}
