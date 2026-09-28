<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag\Provider;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\Config;

/**
 * Site-level Open Graph meta tags emitted on every page.
 *
 * Adds the document-wide tags the per-page providers don't set: og:site_name and og:locale. The X
 * (Twitter) card tags are not set here: the card type depends on whether the page has an image,
 * which only MetaTag\Compositor sees, so it adds them after collecting every provider's tags.
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

        return $tags;
    }
}
