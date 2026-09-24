<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;

/**
 * Queues a rebuild of the sitemap types a change can alter (see InvalidationPolicy).
 *
 * Registered for the catalogue, CMS, store and configuration events in etc/events.xml, and for the
 * save and delete of this module's own per-entity settings — the robots directive decides whether
 * a page is listed, a CMS page's translation group its alternates.
 */
class InvalidateSitemap implements ObserverInterface
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
     * Queue a rebuild of each sitemap type the change can alter.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        foreach ($this->invalidationPolicy->sitemapTypesAffectedBy($observer->getEvent()) as $type) {
            $this->feedInvalidator->invalidateSitemap($type);
        }
    }
}
