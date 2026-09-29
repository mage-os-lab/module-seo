<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\LlmsInvalidationPolicy;
use MageOS\Seo\Model\Rebuild\Invalidator;

/**
 * Invalidates only the /llms.jsonl feed when a product changes.
 *
 * Kept separate from the llms.txt/llms-full.txt invalidation so a product save does not
 * needlessly regenerate the narrative documents (which depend on org/category data, not products).
 */
class InvalidateLlmsJsonlCache implements ObserverInterface
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
     * Queue a rebuild of the llms.jsonl feed on product changes.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->invalidationPolicy->isRelevantChange(FeedRegenerator::GROUP_JSONL, $observer->getEvent())) {
            $this->invalidator->invalidate(FeedRegenerator::GROUP_JSONL);
        }
    }
}
