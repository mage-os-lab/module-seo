<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Sitemap;

/**
 * A map of extra information carried by a sitemap item.
 *
 * Core's sitemap item holds five fixed fields; anything else a sitemap row needs — alternates,
 * a robots directive, video details — has nowhere to go, which is why extending core's sitemap
 * has meant replacing its class. The bag is where that information goes instead: each extension
 * files one entry under its own key, and reads the entries it understands.
 *
 * Entries are objects, so each carries its own type; keys are the code of the extension that
 * owns them (for example `hreflang`, `robots`), so extensions do not overwrite one another.
 *
 * @api
 */
interface DataBagInterface
{
    /**
     * Every entry, keyed by the code of the extension that filed it.
     *
     * @return array<string,object>
     */
    public function getDataBag(): array;

    /**
     * Replace the whole bag.
     *
     * @param array<string,object> $dataBag
     * @throws \InvalidArgumentException When a key is not a string or an entry is not an object
     * @return void
     */
    public function setDataBag(array $dataBag): void;

    /**
     * File one entry, replacing any entry already under that key.
     *
     * @param string $key The code of the extension that owns the entry
     * @param object $value
     * @return void
     */
    public function updateDataBag(string $key, object $value): void;
}
