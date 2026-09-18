<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;

/**
 * Invalidates only the /llms.jsonl feed when a product changes.
 *
 * Kept separate from the llms.txt/llms-full.txt invalidation so a product save does not
 * needlessly regenerate the narrative documents (which depend on org/category data, not products).
 */
class InvalidateLlmsJsonlCache implements ObserverInterface
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
     * Queue a rebuild of the llms.jsonl feed on product changes.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->invalidationPolicy->isRelevantChange(FeedRegenerator::GROUP_JSONL, $observer->getEvent())) {
            $this->feedInvalidator->invalidateJsonl();
        }
    }
}
