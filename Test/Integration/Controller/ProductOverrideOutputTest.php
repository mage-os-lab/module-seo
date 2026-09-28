<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Category\ProductOverrideRepository;

/**
 * A product's Field Value Overrides on its page: an override for one of the template's own fields is
 * built in the template's shape, not written over it as the raw value.
 *
 * The override row goes with the product: mageos_seo_product_override cascades on product delete,
 * which the fixture's revert does.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class ProductOverrideOutputTest extends AbstractController
{
    use ProductPageOutput;

    /**
     * Drop the overrides the repository memoised for the product the fixture removes.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(ProductOverrideRepository::class)->_resetState();
        parent::tearDown();
    }

    /**
     * The docs' own example: a brand override gives a Brand node, with the field not switched on.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, ['price' => 12.5], 'product')]
    public function testABrandOverrideIsABrandNode(): void
    {
        $productId = (int) DataFixtureStorageManager::getStorage()->get('product')->getId();
        Bootstrap::getObjectManager()->get(ProductOverrideRepository::class)
            ->save($productId, 0, ['override_fields' => ['brand' => 'Makers Workshop']]);

        $node = $this->productNode($this->productPage('product'));

        $this->assertSame(['@type' => 'Brand', 'name' => 'Makers Workshop'], $node['brand'] ?? null);
    }
}
