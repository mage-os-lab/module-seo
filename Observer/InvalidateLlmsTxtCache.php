<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;

/**
 * Queues a rebuild of /llms.txt and /llms-full.txt when their source data changes.
 *
 * Registered for category saves, and for product saves that change category product counts
 * (see InvalidationPolicy).
 */
class InvalidateLlmsTxtCache implements ObserverInterface
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
     * Queue the rebuild when the change can affect the llms documents.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->invalidationPolicy->isRelevantChange(FeedRegenerator::GROUP_LLMS, $observer->getEvent())) {
            $this->feedInvalidator->invalidateLlms();
        }
    }
}
