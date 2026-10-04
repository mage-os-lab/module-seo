<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Escaper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\ResolverPool;
use Psr\Log\LoggerInterface;

/**
 * Adds the page's hreflang alternates as page assets: one `<link rel="alternate" hreflang="…">` per
 * code, added the way core adds its canonicals (`PageConfig::addRemotePageAsset()`), so other code
 * can read or replace them through the page config.
 *
 * Each asset has content type `hreflang` and the name `mageos_seo_hreflang_<code>`. The name is
 * needed because the page config keys assets by name, and by URL when there is none: the page's own
 * alternate has the canonical's URL, and x-default usually shares a language's.
 *
 * Runs on layout_generate_blocks_after, before the head is rendered. Switched off per store view
 * by Enable Hreflang Tags. Output depends only on the URL, so it is full-page cacheable.
 */
class AddHreflangLinks implements ObserverInterface
{
    /**
     * Prefix of each alternate's asset name; the hreflang code follows it.
     */
    public const ASSET_NAME_PREFIX = 'mageos_seo_hreflang_';

    /**
     * @param PageConfig $pageConfig
     * @param ResolverPool $resolverPool
     * @param Config $seoConfig
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PageConfig      $pageConfig,
        private readonly ResolverPool    $resolverPool,
        private readonly Config          $seoConfig,
        private readonly Escaper         $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Add an asset for each alternate of the current page.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->seoConfig->isHreflangEnabled()) {
            return;
        }

        try {
            $links = $this->resolverPool->getLinks();
        } catch (\Exception $e) {
            // The page renders without its alternates, as it did when a block's failure was
            // logged by the layout; the error must not take the whole page down.
            $this->logger->error(
                'MageOS_Seo: could not resolve the hreflang alternates: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return;
        }

        foreach ($links as $link) {
            $url = $this->escaper->escapeUrl($link['url']);
            if ($url === '') {
                continue;
            }
            $this->pageConfig->addRemotePageAsset(
                $url,
                'hreflang',
                ['attributes' => ['rel' => 'alternate', 'hreflang' => $link['hreflang']]],
                self::ASSET_NAME_PREFIX . $link['hreflang']
            );
        }
    }
}
