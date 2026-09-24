<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;

/**
 * The sitemaps a change rebuilds: this module's to keep current.
 *
 * A sitemap configured under Marketing → Site Map qualifies when:
 *
 * - it has been generated before — rebuilding keeps sitemaps current, it never makes a first one;
 * - its store view is active; and
 * - its store view uses this module's generator.
 *
 * One definition for the two questions asked of it: which sitemaps to rebuild (Rebuilder), and
 * whether a change is worth queueing at all (Feed\InvalidationPolicy) — so a change is never queued
 * for sitemaps the rebuild would then skip, or skipped for ones it would rebuild.
 */
class RebuildableSitemaps implements ResetAfterRequestInterface
{
    /**
     * Whether any qualifies, once asked in this request.
     *
     * @var bool|null
     */
    private ?bool $exist = null;

    /**
     * @param SitemapCollectionFactory $sitemapCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly SitemapCollectionFactory $sitemapCollectionFactory,
        private readonly StoreManagerInterface    $storeManager,
        private readonly Config                   $seoConfig
    ) {
    }

    /**
     * Every sitemap that qualifies.
     *
     * @return Sitemap[]
     */
    public function all(): array
    {
        $collection = $this->sitemapCollectionFactory->create();
        $collection->addFieldToFilter('sitemap_time', ['notnull' => true]);

        $sitemaps = [];
        /** @var Sitemap $sitemap */
        foreach ($collection as $sitemap) {
            if ($this->qualifies((int) $sitemap->getStoreId())) {
                $sitemaps[] = $sitemap;
            }
        }

        return $sitemaps;
    }

    /**
     * Whether any sitemap qualifies.
     *
     * Asked on every save a rebuild might follow, so it is answered once per request; a sitemap
     * generated for the first time later in the same request is caught by the nightly run.
     *
     * @return bool
     */
    public function exist(): bool
    {
        return $this->exist ??= $this->all() !== [];
    }

    /**
     * Forget the answer between worker-mode requests.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->exist = null;
    }

    /**
     * Whether a sitemap of the store view is this module's to rebuild.
     *
     * @param int $storeId
     * @return bool
     */
    private function qualifies(int $storeId): bool
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            return false;
        }

        return (bool) $store->getIsActive() && $this->seoConfig->isSitemapGeneratorEnabled($storeId);
    }
}
