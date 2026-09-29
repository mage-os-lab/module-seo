<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Sitemap;

use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface as CoreItemProviderInterface;

/**
 * A sitemap item provider that streams, and says which sitemap file its items belong in.
 *
 * Still a core item provider — `getItems()` returns the whole list, as core's generator expects —
 * so a provider written against this interface works whichever generator runs. The generator in
 * this module uses `iterateItems()` instead, so a catalogue is never held in memory at once, and
 * `getType()` to write each kind of page to its own file under one sitemap index.
 *
 * Register a provider on core's `Magento\Sitemap\Model\ItemProvider\Composite`, as for any core
 * provider; this module's composite receives it from there.
 *
 * @api
 */
interface ItemProviderInterface extends CoreItemProviderInterface
{
    public const TYPE_PAGES      = 'pages';
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_PRODUCTS   = 'products';

    /**
     * Where items from providers that do not say go.
     */
    public const TYPE_OTHER = 'other';

    /**
     * The sitemap file the items belong in: one of the TYPE_* constants, or a type of its own.
     *
     * A type of its own gets a file of its own. Use lower-case letters, digits and hyphens — the
     * type is part of the file name.
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Every item for the store view, one at a time.
     *
     * @param int $storeId
     * @return iterable<SitemapItemInterface>
     */
    public function iterateItems(int $storeId): iterable;

    /**
     * Every item for the store view, as one list — for core's sitemap generator.
     *
     * @param int $storeId
     * @return SitemapItemInterface[]
     */
    public function getItems($storeId);
}
