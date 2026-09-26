<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\StructuredData\SpeakableSpecification;

/**
 * The WebPage node for a CMS page, the home page included.
 *
 * Its `url` and `@id` are CmsPageResolver::currentUrl(): the store base URL on the home page (so
 * `{base}/#webpage`, beside the WebSite's `{base}/#website`), the base URL plus the identifier on
 * every other CMS page. With Speakable on, this node carries the page's SpeakableSpecification.
 */
class CmsPageSchemaProvider implements StructuredDataProviderInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     * @param SpeakableSpecification $speakable
     */
    public function __construct(
        private readonly CmsPageResolver        $cmsPageResolver,
        private readonly SpeakableSpecification $speakable
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

            $url = $this->cmsPageResolver->currentUrl();

            $schema = [
                '@context' => 'https://schema.org',
                '@type'    => 'WebPage',
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

            $speakable = $this->speakable->get();
            if ($speakable !== null) {
                $schema['speakable'] = $speakable;
            }

            return [$schema];
        } catch (\Exception) {
            return [];
        }
    }
}
