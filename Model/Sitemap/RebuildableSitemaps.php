<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;

/**
 * The sitemaps this module keeps current by rebuilding them.
 *
 * A sitemap configured under Marketing → Site Map can be rebuilt when its store view is active and
 * uses this module's generator — generated before or not: one with no file is written whole.
 *
 * A change rebuilds those whose store view also has Rebuild on Change on. A rebuild asked for by
 * hand (`mageos:seo:feeds:regenerate -g sitemap-…`) takes every one that can be rebuilt, as
 * `indexer:reindex` runs whatever an indexer's mode.
 *
 * A sitemap is missing when its index file is not in pub/ — never generated, or its files have
 * gone. Those a change rebuilds get a first build of their own (RebuildGroup::MISSING), queued when
 * a Site Map entry is saved and after every setup run, so they do not wait for the next change.
 *
 * One definition for the questions asked of it: which sitemaps to rebuild (Rebuilder), and whether
 * a change is worth queueing at all (Feed\InvalidationPolicy) — so a change is never queued for
 * sitemaps the rebuild would then skip, or skipped for ones it would rebuild.
 */
class RebuildableSitemaps implements ResetAfterRequestInterface
{
    /**
     * Whether a change rebuilds any, once asked in this request.
     *
     * @var bool|null
     */
    private ?bool $exist = null;

    /**
     * @param SitemapCollectionFactory $sitemapCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param Config $seoConfig
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly SitemapCollectionFactory $sitemapCollectionFactory,
        private readonly StoreManagerInterface    $storeManager,
        private readonly Config                   $seoConfig,
        private readonly Filesystem               $filesystem
    ) {
    }

    /**
     * Every sitemap that can be rebuilt, whether or not a change rebuilds it.
     *
     * @return Sitemap[]
     */
    public function all(): array
    {
        $sitemaps = [];
        /** @var Sitemap $sitemap */
        foreach ($this->sitemapCollectionFactory->create() as $sitemap) {
            if ($this->qualifies((int) $sitemap->getStoreId())) {
                $sitemaps[] = $sitemap;
            }
        }

        return $sitemaps;
    }

    /**
     * The sitemaps a change rebuilds: those that can be, on a store view with Rebuild on Change on.
     *
     * @return Sitemap[]
     */
    public function onChange(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Sitemap $sitemap): bool => $this->isRebuiltOnChangeEnabled($sitemap)
        ));
    }

    /**
     * The sitemaps a change rebuilds that have no file: those a first build writes.
     *
     * @return Sitemap[]
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->onChange(),
            fn (Sitemap $sitemap): bool => $this->isMissing($sitemap)
        ));
    }

    /**
     * Whether a change rebuilds the sitemap: it can be rebuilt, and its store view has Rebuild on Change on.
     *
     * @param Sitemap $sitemap
     * @return bool
     */
    public function isRebuiltOnChange(Sitemap $sitemap): bool
    {
        return $this->qualifies((int) $sitemap->getStoreId()) && $this->isRebuiltOnChangeEnabled($sitemap);
    }

    /**
     * Whether the sitemap's index file is not in pub/.
     *
     * @param Sitemap $sitemap
     * @return bool
     */
    public function isMissing(Sitemap $sitemap): bool
    {
        $path = rtrim((string) $sitemap->getSitemapPath(), '/') . '/' . $sitemap->getSitemapFilename();

        return !$this->filesystem->getDirectoryRead(DirectoryList::PUB)->isFile($path);
    }

    /**
     * Whether a change rebuilds any sitemap.
     *
     * Asked on every save a rebuild might follow, so it is answered once per request; a sitemap
     * generated for the first time later in the same request is caught by the nightly run.
     *
     * @return bool
     */
    public function exist(): bool
    {
        return $this->exist ??= $this->onChange() !== [];
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
     * Whether the sitemap's store view has Rebuild on Change on.
     *
     * @param Sitemap $sitemap
     * @return bool
     */
    private function isRebuiltOnChangeEnabled(Sitemap $sitemap): bool
    {
        return $this->seoConfig->isSitemapRebuildOnChangeEnabled((int) $sitemap->getStoreId());
    }

    /**
     * Whether a sitemap of the store view can be rebuilt by this module.
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
