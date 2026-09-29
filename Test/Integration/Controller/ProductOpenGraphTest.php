<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * A rendered product page carries one set of Open Graph tags, not core's and this module's both.
 *
 * Core's `opengraph.general` block and this module's meta tags both emit og:title and the rest,
 * so until core's block was removed every product page had two of each. Rendering the page is the
 * only way to see that: the removal happens on the generated layout.
 *
 * `product:availability` tells the two apart — this module always emits it and core never does.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class ProductOpenGraphTest extends AbstractController
{
    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testEachOpenGraphTagAppearsOnce(): void
    {
        $body = $this->productPage();

        $this->assertSame(1, substr_count($body, '<meta property="og:title"'), 'og:title is not duplicated.');
        $this->assertSame(1, substr_count($body, '<meta property="og:image"'), 'og:image is not duplicated.');
        $this->assertStringContainsString(
            '<meta property="product:availability"',
            $body,
            'The tags left are this module\'s.'
        );
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/og_tags/enabled 0
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testCoresTagsStayWhenThisModulesAreSwitchedOff(): void
    {
        // Removing core's block unconditionally would leave the page with no Open Graph at all.
        $body = $this->productPage();

        $this->assertSame(1, substr_count($body, '<meta property="og:title"'), 'Core\'s og:title is still there.');
        $this->assertStringNotContainsString(
            '<meta property="product:availability"',
            $body,
            'None of this module\'s tags are emitted.'
        );
    }

    /**
     * Render the fixture product's page and return its HTML.
     *
     * @return string
     */
    private function productPage(): string
    {
        $productId = (int) DataFixtureStorageManager::getStorage()->get('product')->getId();

        $this->dispatch('catalog/product/view/id/' . $productId);

        return (string) $this->getResponse()->getBody();
    }
}
