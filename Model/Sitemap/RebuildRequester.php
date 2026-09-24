<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use MageOS\Seo\Api\Sitemap\RebuildRequesterInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;

/**
 * Queues a sitemap type's rebuild for another module, through the same path this module's own
 * change observers take (Feed\FeedInvalidator).
 *
 * A type no registered provider has is refused where it is asked for. Queued, it would reach the
 * consumer, which can only log it and drop it — a misspelt type would never rebuild anything, and
 * nothing would say so where the mistake was made.
 */
class RebuildRequester implements RebuildRequesterInterface
{
    /**
     * @param Rebuilder $rebuilder
     * @param FeedInvalidator $feedInvalidator
     */
    public function __construct(
        private readonly Rebuilder       $rebuilder,
        private readonly FeedInvalidator $feedInvalidator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function request(string $type): void
    {
        if (!$this->rebuilder->hasType($type)) {
            throw new \InvalidArgumentException(\sprintf(
                'No sitemap provider has the type "%s". Types: %s, or "%s" for every type.',
                $type,
                implode(', ', $this->rebuilder->types()),
                self::ALL_TYPES
            ));
        }

        $this->feedInvalidator->invalidateSitemap($type);
    }
}
