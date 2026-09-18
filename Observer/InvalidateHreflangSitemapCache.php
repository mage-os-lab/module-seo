<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;

/**
 * Queues a rebuild of the hreflang sitemap when catalogue, CMS or store data changes in a
 * way that can change its URLs (see InvalidationPolicy).
 */
class InvalidateHreflangSitemapCache implements ObserverInterface
{
    /**
     * @param FeedInvalidator $feedInvalidator
     * @param InvalidationPolicy $invalidationPolicy
     */
    public function __construct(
        private readonly FeedInvalidator    $feedInvalidator,
        private readonly InvalidationPolicy $invalidationPolicy
    ) {
    }

    /**
     * Queue the rebuild when the change can affect the sitemap's URLs.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->invalidationPolicy->isRelevantChange(FeedRegenerator::GROUP_HREFLANG, $observer->getEvent())) {
            $this->feedInvalidator->invalidateHreflangSitemap();
        }
    }
}
