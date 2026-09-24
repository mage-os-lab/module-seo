<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use MageOS\Seo\Model\Feed\FeedCache;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Feed\RegenerationRequester;
use Psr\Log\LoggerInterface;

/**
 * Removes what the retired `/hreflang-sitemap.xml` left behind.
 *
 * The alternates are written into `sitemap.xml` now (docs/sitemap.md), and the old path is gone —
 * it answers 404. Three things would otherwise outlive it:
 *
 * - its files, `hreflang-sitemap*.xml`, in every store directory of feed storage — that pattern
 *   and nothing else, in every directory there, including a deleted store view's;
 * - the queue's pending flag for its rebuild group, which no consumer would ever clear;
 * - cached copies of its responses, which were sent with `s-maxage=86400`: Varnish or the full
 *   page cache would go on serving them for up to a day.
 *
 * With feed storage in a host-local var/, only the host running setup:upgrade is cleaned; the files
 * on the others can no longer be reached, and can be deleted by hand.
 *
 * Not revertible: the files were generated output, and the path that served them is gone.
 */
class RemoveHreflangSitemap implements DataPatchInterface
{
    private const FILES = 'hreflang-sitemap*.xml';

    private const GROUP = 'hreflang';

    private const CACHE_TAG = 'MAGEOS_SEO_HREFLANG_SITEMAP';

    /**
     * @param FeedStorage $feedStorage
     * @param RegenerationRequester $regenerationRequester
     * @param FeedCache $feedCache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FeedStorage           $feedStorage,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly FeedCache             $feedCache,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        foreach ($this->feedStorage->listStoreDirectories() as $storeId) {
            $this->feedStorage->deleteForStore(self::FILES, $storeId);
        }

        // The one place that knows the flag's name; clearing it is what a consumer would have done.
        $this->regenerationRequester->acknowledge(self::GROUP);

        try {
            $this->feedCache->purgeTags([self::CACHE_TAG]);
        } catch (\Throwable $e) {
            // Not worth failing setup:upgrade over: a cached copy expires within a day.
            $this->logger->error(
                'MageOS_Seo: could not purge cached /hreflang-sitemap.xml responses: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
