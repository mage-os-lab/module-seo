<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag\Provider;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\Config;

/**
 * Site-level Open Graph and Twitter meta tags emitted on every page.
 *
 * Adds the document-wide tags the per-page providers don't set: og:site_name, og:locale and the
 * Twitter card type. Twitter intentionally falls back to the og:title / og:description / og:image
 * already emitted per page, so declaring the card type here is enough for a complete Twitter card
 * without duplicating per-page values.
 */
class SiteMetaProvider implements MetaTagProviderInterface
{
    /**
     * @param Config $seoConfig
     * @param StoreManagerInterface $storeManager
     * @param OrganisationRepositoryInterface $organisationRepository
     */
    public function __construct(
        private readonly Config                          $seoConfig,
        private readonly StoreManagerInterface           $storeManager,
        private readonly OrganisationRepositoryInterface $organisationRepository
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['*'];
    }

    /**
     * @inheritdoc
     */
    public function getMetaTags(): array
    {
        if (!$this->seoConfig->isOgTagsEnabled()) {
            return [];
        }

        $store     = $this->storeManager->getStore();
        $storeId   = (int) $store->getId();
        $websiteId = (int) $this->storeManager->getWebsite()->getId();
        $org       = $this->organisationRepository->getForScope($storeId, $websiteId);

        $siteName = $org->getName() ?: (string) $store->getName();
        $locale   = $this->seoConfig->getLocaleCode($storeId);

        $tags = [];
        if ($siteName !== '') {
            $tags[] = ['property' => 'og:site_name', 'content' => $siteName];
        }
        if ($locale !== '') {
            $tags[] = ['property' => 'og:locale', 'content' => $locale];
        }
        $tags[] = ['name' => 'twitter:card', 'content' => 'summary_large_image'];

        return $tags;
    }
}
