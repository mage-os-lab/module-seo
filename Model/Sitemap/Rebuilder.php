<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Rewrites one type of page in every sitemap this module generates, after a change to it.
 *
 * Reached from the feed queue: a change to products, categories or pages queues `sitemap-{type}`
 * (see RebuildGroup), and the consumer hands the type here. Each sitemap configured under Marketing →
 * Site Map is rewritten — that type's files only, the others kept — when:
 *
 * - it has been generated before: this keeps sitemaps current, it never makes a first one;
 * - its store view is active; and
 * - its store view uses this module's generator.
 *
 * Each is written as core's cron writes it, emulating its store view's frontend. One that fails is
 * logged and the rest carry on; one being written by another process is left for the retry the
 * caller queues.
 */
class Rebuilder
{
    /**
     * @param SitemapCollectionFactory $sitemapCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param Config $seoConfig
     * @param Generator $generator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SitemapCollectionFactory $sitemapCollectionFactory,
        private readonly StoreManagerInterface    $storeManager,
        private readonly Emulation                $emulation,
        private readonly Config                   $seoConfig,
        private readonly Generator                $generator,
        private readonly LoggerInterface          $logger
    ) {
    }

    /**
     * Whether the generator writes the type, so a request for it can be carried out.
     *
     * @param string $type
     * @return bool
     */
    public function hasType(string $type): bool
    {
        return \in_array($type, $this->generator->getTypes(), true);
    }

    /**
     * Rewrite the type in every sitemap that qualifies.
     *
     * @param string $type
     * @return array<int,string> Error message per sitemap ID that failed
     * @throws SitemapRebuildInProgressException When one was being written by another process —
     *                                           after all the others are done
     */
    public function rebuild(string $type): array
    {
        $failures = [];
        $busy     = [];

        $collection = $this->sitemapCollectionFactory->create();
        $collection->addFieldToFilter('sitemap_time', ['notnull' => true]);

        /** @var Sitemap $sitemap */
        foreach ($collection as $sitemap) {
            $storeId = (int) $sitemap->getStoreId();
            if (!$this->qualifies($storeId)) {
                continue;
            }

            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                $this->generator->regenerate($sitemap, [$type]);
            } catch (SitemapRebuildInProgressException) {
                $busy[] = (string) $sitemap->getSitemapFilename();
            } catch (\Throwable $e) {
                $failures[(int) $sitemap->getId()] = $e->getMessage();
                $this->logger->error(
                    \sprintf(
                        'MageOS_Seo: rebuilding the %s of sitemap %s failed: %s',
                        $type,
                        $sitemap->getSitemapFilename(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        if ($busy !== []) {
            throw new SitemapRebuildInProgressException(__(
                'Sitemaps being written by another process were not rebuilt: %1.',
                implode(', ', $busy)
            ));
        }

        return $failures;
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
