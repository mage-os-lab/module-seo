<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;

/**
 * Registers the llms documents with the rebuild queue: `llms` (/llms.txt and /llms-full.txt) and
 * `jsonl` (/llms.jsonl).
 *
 * The queue's consumer, the regenerate command and the setup run find these groups here; the
 * documents themselves are built by FeedRegenerator, and whether one is worth queueing is
 * LlmsInvalidationPolicy's call.
 */
class LlmsRebuildHandler implements GroupHandlerInterface
{
    /**
     * @param FeedRegenerator $feedRegenerator
     * @param LlmsInvalidationPolicy $invalidationPolicy
     */
    public function __construct(
        private readonly FeedRegenerator        $feedRegenerator,
        private readonly LlmsInvalidationPolicy $invalidationPolicy
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getGroups(): array
    {
        return FeedRegenerator::GROUPS;
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(string $group): bool
    {
        return $this->invalidationPolicy->isGroupEnabled($group);
    }

    /**
     * @inheritDoc
     */
    public function rebuild(?string $group): array
    {
        return $this->feedRegenerator->regenerate($group);
    }
}
