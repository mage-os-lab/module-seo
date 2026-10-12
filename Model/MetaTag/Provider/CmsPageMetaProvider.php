<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag\Provider;

use Magento\Cms\Model\Template\FilterProvider;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * A CMS page's Open Graph tags: its title, URL, meta description and first image.
 *
 * The image is the first `<img>` in the page's content, as stored. Page Builder and the WYSIWYG
 * editor store its src as a `{{media url=…}}` directive, so that src, and only that, goes through
 * the page filter to become a URL a crawler can fetch (review M10). Filtering the whole content
 * would render every widget on the page a second time, on every uncached request, to find one image.
 */
class CmsPageMetaProvider implements MetaTagProviderInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     * @param Config $seoConfig
     * @param StoreManagerInterface $storeManager
     * @param FilterProvider $filterProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CmsPageResolver       $cmsPageResolver,
        private readonly Config                $seoConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly FilterProvider        $filterProvider,
        private readonly LoggerInterface       $logger
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

            // The base URL still prefixes a relative og:image; og:url is the page's own URL.
            $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/');
            $url     = $this->cmsPageResolver->currentUrl();

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
                $image = trim((string) $this->filterProvider->getPageFilter()->filter($image));
            }
            if ($image !== '') {
                if (!str_starts_with($image, 'http')) {
                    $image = $baseUrl . '/' . ltrim($image, '/');
                }
                $tags[] = ['property' => 'og:image', 'content' => $image];
            }

            return $tags;
        } catch (\Exception $e) {
            // The page renders without Open Graph tags, and the log says why.
            $this->logger->error(
                'MageOS_Seo: could not build the CMS page\'s Open Graph tags: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return [];
        }
    }

    /**
     * Extract the first image's src from an HTML string, as stored: possibly a directive.
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
