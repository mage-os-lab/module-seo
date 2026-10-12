<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Review M9, decision D4a: a product page describes its product once, in this module's JSON-LD.
 *
 * Magento_Catalog's product page layout marks the page up as a schema.org Product in microdata
 * (`itemscope itemtype="http://schema.org/Product"` on the body and the page wrapper, `itemprop`s on
 * the name, SKU, description and image), and Magento_Review nests its AggregateRating and Review
 * items in it. Next to the JSON-LD that made two Product entities. Remove the Theme's Product
 * Microdata (on by default) strips the microdata from the product page while this module's Product
 * JSON-LD is on. All of it: without the outer Product, the review items would be left as orphans.
 *
 * App isolation: whether a page has a product node is read from the request's SchemaRegistry, which
 * must not carry one test's node into the next.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class ProductMicrodataTest extends AbstractController
{
    use ProductPageOutput;

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testTheThemesMicrodataIsRemovedByDefault(): void
    {
        $body = $this->productPage('product');

        $this->assertStringNotContainsString('itemscope', $body);
        $this->assertStringNotContainsString('itemtype=', $body);
        $this->assertStringNotContainsString('itemprop=', $body);
        $this->assertSame('Product', $this->productNode($body)['@type'] ?? null, 'The JSON-LD Product stays.');
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/structured_data/remove_theme_microdata 0
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testTheMicrodataStaysWhenTheSettingIsOff(): void
    {
        $body = $this->productPage('product');

        $this->assertStringContainsString('itemtype="http://schema.org/Product"', $body);
    }

    /**
     * Without this module's JSON-LD, the theme's microdata is the only description of the product.
     *
     * @magentoConfigFixture current_store mageos_seo_general/structured_data/enabled 0
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testTheMicrodataStaysWhileThisModulesStructuredDataIsOff(): void
    {
        $body = $this->productPage('product');

        $this->assertStringContainsString('itemtype="http://schema.org/Product"', $body);
    }
}
