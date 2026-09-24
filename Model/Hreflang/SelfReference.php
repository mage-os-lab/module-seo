<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

/**
 * The rule every hreflang set must pass: it includes the page it is declared on.
 *
 * Google discards an alternate set that does not list the URL it appears on. So a page — in its
 * head, or as a sitemap entry — declares its set only when the set has a link for its own store
 * view, and, where the page's own address is known, at that address. The head and the sitemap
 * both ask here, so they cannot disagree about which sets are valid.
 */
class SelfReference
{
    /**
     * Whether the links include the store view's own — at the given URL, when one is given.
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $links
     * @param int $storeId
     * @param string|null $url The page's own address in that store view, when known
     * @return bool
     */
    public function includes(array $links, int $storeId, ?string $url = null): bool
    {
        foreach ($links as $link) {
            if ($link['store_id'] === $storeId && ($url === null || $link['url'] === $url)) {
                return true;
            }
        }

        return false;
    }
}
