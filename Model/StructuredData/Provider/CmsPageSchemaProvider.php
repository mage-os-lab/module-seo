<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Framework\View\Layout;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;

class CmsPageSchemaProvider implements StructuredDataProviderInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     * @param StoreManagerInterface $storeManager
     * @param Layout $layout
     */
    public function __construct(
        private readonly CmsPageResolver $cmsPageResolver,
        private readonly StoreManagerInterface   $storeManager,
        private readonly Layout                  $layout,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['cms_page_view'];
    }

    /**
     * @inheritdoc
     */
    public function getSchemas(): array
    {
        try {
            $page = $this->cmsPageResolver->resolve();
            if ($page === null) {
                return [];
            }

            $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/');
            $url     = $baseUrl . '/' . ltrim((string) $page->getIdentifier(), '/');
            $type    = $this->isHomepage() ? 'AboutPage' : 'WebPage';

            $schema = [
                '@context' => 'https://schema.org',
                '@type'    => $type,
                '@id'      => $url . '#webpage',
                'name'     => (string) $page->getTitle(),
                'url'      => $url,
            ];

            $metaDescription = trim((string) $page->getMetaDescription());
            if ($metaDescription !== '') {
                $schema['description'] = mb_substr($metaDescription, 0, 500);
            }

            $keywords = trim((string) $page->getMetaKeywords());
            if ($keywords !== '') {
                $schema['keywords'] = $keywords;
            }

            return [$schema];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Check if the current page is the homepage.
     *
     * @return bool
     */
    private function isHomepage(): bool
    {
        return \in_array('cms_index_index', $this->layout->getUpdate()->getHandles(), true);
    }
}
