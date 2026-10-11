<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Review finding M1: the category ItemList describes the products the listing shows, and never
 * loads the product collection itself.
 *
 * A "Static block only" category renders no product list, so its collection is never loaded by the
 * page. Reading it there loaded every product the category matched, on every uncached request, to
 * list products the page does not show.
 *
 * Database isolation is off: a category listing comes from the search index, which a product
 * reaches through a commit callback that an isolated test never commits. The fixtures revert
 * themselves.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class CategoryItemListOutputTest extends AbstractController
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::getObjectManager()->get(CacheManager::class)->clean(['full_page']);
    }

    /**
     * @return void
     */
    #[DataFixture(
        CategoryFixture::class,
        ['name' => 'Static Block Only', 'display_mode' => Category::DM_PAGE],
        'category'
    )]
    #[DataFixture(
        ProductFixture::class,
        ['name' => 'Static Block Product', 'category_ids' => ['$category.id$']],
        'product'
    )]
    public function testAStaticBlockOnlyCategoryHasNoItemListAndLoadsNoProducts(): void
    {
        $jsonLd = $this->jsonLd($this->categoryPage('category'));

        $this->assertStringNotContainsString('"@type":"ItemList"', $jsonLd);
        // A collection never loaded says null rather than false.
        $this->assertNotTrue(
            Bootstrap::getObjectManager()->get(LayerResolver::class)->get()->getProductCollection()->isLoaded(),
            'Nothing on the page loads the product collection.'
        );
    }

    /**
     * @return void
     */
    #[DataFixture(
        CategoryFixture::class,
        ['name' => 'Products Only', 'display_mode' => Category::DM_PRODUCT],
        'category'
    )]
    #[DataFixture(ProductFixture::class, ['name' => 'Listed Product', 'category_ids' => ['$category.id$']], 'product')]
    public function testAProductsCategoryListsItsProducts(): void
    {
        $body   = $this->categoryPage('category');
        $jsonLd = $this->jsonLd($body);

        $this->assertStringContainsString(
            '"@type":"ItemList"',
            $jsonLd,
            \sprintf(
                'The listing shows the product: %s. The JSON-LD on the page: %s',
                str_contains($body, 'Listed Product') ? 'yes' : 'no',
                $jsonLd
            )
        );
        $this->assertStringContainsString('"name":"Listed Product"', $jsonLd);
    }

    /**
     * The page's JSON-LD blocks, one per line.
     *
     * @param string $body
     * @return string
     */
    private function jsonLd(string $body): string
    {
        preg_match_all('#<script type="application/ld\+json"[^>]*>\s*(.*?)\s*</script>#s', $body, $matches);

        return implode("\n", $matches[1]);
    }

    /**
     * Render a fixture category's page.
     *
     * @param string $fixture
     * @return string
     */
    private function categoryPage(string $fixture): string
    {
        $categoryId = (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
        $this->dispatch('catalog/category/view/id/' . $categoryId);

        return (string) $this->getResponse()->getBody();
    }
}
