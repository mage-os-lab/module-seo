<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag\Provider;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Config;

class CmsPageMetaProvider implements MetaTagProviderInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     * @param Config $seoConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CmsPageResolver       $cmsPageResolver,
        private readonly Config                $seoConfig,
        private readonly StoreManagerInterface $storeManager,
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
    public function getMetaTags(): array
    {
        if (!$this->seoConfig->isOgTagsEnabled()) {
            return [];
        }

        try {
            $page = $this->cmsPageResolver->resolve();
            if ($page === null) {
                return [];
            }

            $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/');
            $url     = $baseUrl . '/' . ltrim((string) $page->getIdentifier(), '/');

            $tags = [
                ['property' => 'og:type',  'content' => 'website'],
                ['property' => 'og:title', 'content' => (string) $page->getTitle()],
                ['property' => 'og:url',   'content' => $url],
            ];

            $metaDescription = trim((string) $page->getMetaDescription());
            if ($metaDescription !== '') {
                $tags[] = ['property' => 'og:description', 'content' => mb_substr($metaDescription, 0, 160)];
            }

            $image = $this->extractFirstImage((string) $page->getContent());
            if ($image !== '') {
                if (!str_starts_with($image, 'http')) {
                    $image = $baseUrl . '/' . ltrim($image, '/');
                }
                $tags[] = ['property' => 'og:image', 'content' => $image];
            }

            return $tags;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Extract the first image URL from an HTML string.
     *
     * @param string $html
     * @return string
     */
    private function extractFirstImage(string $html): string
    {
        if ($html === '') {
            return '';
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
