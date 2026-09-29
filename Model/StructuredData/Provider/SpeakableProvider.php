<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\StructuredData\SpeakableSpecification;

/**
 * A product page's WebPage node, carrying the page's SpeakableSpecification.
 *
 * Speakable sits on the node that describes the page (see SpeakableSpecification). CMS pages,
 * categories and blog posts have one of their own and carry it there. A product page has only the
 * Product (or ProductGroup) node, and `speakable` isn't a Product property, so with Speakable on this
 * gives the page a WebPage of its own: `{url}#webpage`, its `mainEntity` the product node
 * (`{url}#product`). With Speakable off it emits nothing.
 */
class SpeakableProvider implements StructuredDataProviderInterface
{
    /**
     * @param CurrentEntity $currentEntity
     * @param SpeakableSpecification $speakable
     */
    public function __construct(
        private readonly CurrentEntity          $currentEntity,
        private readonly SpeakableSpecification $speakable
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_product_view'];
    }

    /**
     * @inheritdoc
     */
    public function getSchemas(): array
    {
        $speakable = $this->speakable->get();
        if ($speakable === null) {
            return [];
        }

        $product = $this->currentEntity->getProduct();
        if ($product === null) {
            return [];
        }

        /** @var \Magento\Catalog\Model\Product $product */
        // The URL AbstractBuilder::buildBase() builds the product node's @id from.
        $url = (string) $product->getProductUrl();

        return [[
            '@context'   => 'https://schema.org',
            '@type'      => 'WebPage',
            '@id'        => $url . '#webpage',
            'url'        => $url,
            'name'       => (string) $product->getName(),
            'mainEntity' => ['@id' => $url . '#product'],
            'speakable'  => $speakable,
        ]];
    }
}
