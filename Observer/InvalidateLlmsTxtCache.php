<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\LlmsInvalidationPolicy;
use MageOS\Seo\Model\Rebuild\Invalidator;

/**
 * Queues a rebuild of /llms.txt and /llms-full.txt when their source data changes.
 *
 * Registered for category saves, product saves that change category product counts, the
 * configuration the documents show (see LlmsInvalidationPolicy), and the save and delete of a FAQ or
 * of the Organization — through their models' own events, so every way of saving them counts, not
 * only the admin form.
 */
class InvalidateLlmsTxtCache implements ObserverInterface
{
    /**
     * @param Invalidator $invalidator
     * @param LlmsInvalidationPolicy $invalidationPolicy
     */
    public function __construct(
        private readonly Invalidator            $invalidator,
        private readonly LlmsInvalidationPolicy $invalidationPolicy
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
            $this->invalidator->invalidate(FeedRegenerator::GROUP_LLMS);
        }
    }
}
