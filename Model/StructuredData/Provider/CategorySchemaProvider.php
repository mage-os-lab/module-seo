<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\StructuredData\SpeakableSpecification;

class CategorySchemaProvider implements StructuredDataProviderInterface
{
    /**
     * The category configuration, the request and the SEO config left with the ItemList node
     * when it moved to Block\ItemListJsonLd; this provider now emits CollectionPage only. With
     * Speakable on, the CollectionPage carries the page's SpeakableSpecification.
     *
     * @param LayerResolver $layerResolver
     * @param SpeakableSpecification $speakable
     */
    public function __construct(
        private readonly LayerResolver          $layerResolver,
        private readonly SpeakableSpecification $speakable
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_category_view'];
    }

    /**
     * @inheritdoc
     */
    public function getSchemas(): array
    {
        try {
            $layer    = $this->layerResolver->get();
            $category = $layer->getCurrentCategory();

            if (!$category || !$category->getId()) {
                return [];
            }

            $catUrl = (string) $category->getUrl();

            $schemas = [];

            // CollectionPage schema
            $collectionPage = [
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                '@id'      => $catUrl . '#collectionpage',
                'name'     => $category->getName(),
                'url'      => $catUrl,
            ];

            $description = (string) $category->getDescription();
            if ($description !== '') {
                $collectionPage['description'] = $this->cleanDescription($description);
            }

            $speakable = $this->speakable->get();
            if ($speakable !== null) {
                $collectionPage['speakable'] = $speakable;
            }

            $schemas[] = $collectionPage;

            // The ItemList node is emitted separately, at end of body, by Block\ItemListJsonLd:
            // it describes the products the listing is showing, and that listing does not exist
            // yet while this block renders in head.

            return $schemas;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Strip PageBuilder inline styles, CSS blocks, and HTML tags from a category description.
     *
     * PageBuilder outputs inline &lt;style&gt; blocks and data-pb-style attributes
     * that survive strip_tags() — these must be removed first.
     *
     * @param string $html
     * @return string
     */
    private function cleanDescription(string $html): string
    {
        // Remove <style> blocks entirely (PageBuilder inline CSS)
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        // Remove all remaining HTML tags
        $clean = strip_tags($clean);

        // Collapse whitespace
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        return mb_substr($clean, 0, 500);
    }
}
