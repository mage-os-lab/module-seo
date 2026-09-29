<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface as CoreItemProviderInterface;
use Magento\Sitemap\Model\SitemapItemInterface as CoreSitemapItemInterface;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;

/**
 * A provider written against core's interface only, as this module's generator uses providers.
 *
 * Another module's provider registered on core's composite knows nothing of types or data bags:
 * its items go in the "other" file, as items without an entity, so extensions that work from the
 * entity leave them alone. It lists what it always listed.
 */
class CoreProviderAdapter implements ItemProviderInterface
{
    /**
     * @param CoreItemProviderInterface $provider
     * @param SitemapItemFactory $itemFactory
     */
    public function __construct(
        private readonly CoreItemProviderInterface $provider,
        private readonly SitemapItemFactory        $itemFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return self::TYPE_OTHER;
    }

    /**
     * @inheritdoc
     */
    public function getItems($storeId)
    {
        return iterator_to_array($this->iterateItems((int) $storeId), false);
    }

    /**
     * @inheritdoc
     */
    public function iterateItems(int $storeId): iterable
    {
        foreach ($this->provider->getItems($storeId) as $item) {
            yield $item instanceof SitemapItemInterface ? $item : $this->adapt($item);
        }
    }

    /**
     * The same item, with an empty bag and no entity.
     *
     * @param CoreSitemapItemInterface $item
     * @return SitemapItemInterface
     */
    private function adapt(CoreSitemapItemInterface $item): SitemapItemInterface
    {
        return $this->itemFactory->create([
            'url'             => $item->getUrl(),
            'priority'        => $item->getPriority(),
            'changeFrequency' => $item->getChangeFrequency(),
            'updatedAt'       => $item->getUpdatedAt(),
            'images'          => $item->getImages(),
        ]);
    }
}
