<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Rebuild\Invalidator;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Sitemap\InvalidationPolicy;
use MageOS\Seo\Model\Sitemap\RebuildGroup;

/**
 * Queues a rebuild of the sitemap types a change can alter (see Sitemap\InvalidationPolicy).
 *
 * Registered for the catalogue, CMS, store and configuration events in etc/events.xml, and for the
 * save and delete of this module's own per-entity settings — the robots directive decides whether
 * a page is listed, a CMS page's translation group its alternates.
 */
class InvalidateSitemap implements ObserverInterface
{
    /**
     * @param Invalidator $invalidator
     * @param InvalidationPolicy $invalidationPolicy
     * @param RegenerationRequester $regenerationRequester
     * @param RebuildGroup $rebuildGroup
     */
    public function __construct(
        private readonly Invalidator           $invalidator,
        private readonly InvalidationPolicy    $invalidationPolicy,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly RebuildGroup          $rebuildGroup
    ) {
    }

    /**
     * Queue a rebuild of each sitemap type the change can alter.
     *
     * Switching sitemap generation or Rebuild on Change on or off queues every type directly: the
     * Invalidator's "is any sitemap rebuilt?" would be answered from the configuration before the
     * save (InvalidationPolicy::isSitemapSwitch()).
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->invalidationPolicy->isSitemapSwitch($observer->getEvent())) {
            $this->regenerationRequester->request($this->rebuildGroup->forType(RebuildGroup::ALL_TYPES));
            return;
        }

        foreach ($this->invalidationPolicy->sitemapTypesAffectedBy($observer->getEvent()) as $type) {
            $this->invalidator->invalidateSitemap($type);
        }
    }
}
