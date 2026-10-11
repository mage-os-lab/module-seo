<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Product\SchemaCategory;
use PHPUnit\Framework\TestCase;

/**
 * Review low item, decision D9b: which category's settings choose a product's schema template.
 *
 * It was reset() of getCategoryIds(): whichever assignment came first, possibly a category in
 * another website's tree. Now:
 * - the category in the URL, the `category` parameter a category-path product URL carries, when it
 *   is one of the product's categories under the store's root;
 * - otherwise the deepest of the product's categories under the store's root, ties to the lowest ID.
 *
 * Never the `current_category` registry: on a product page core fills it from the category the
 * visitor last browsed, and the page cache would serve that visitor's choice to everyone.
 *
 * @magentoAppArea frontend
 */
class SchemaCategoryTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->get(RequestInterface::class)->setParam('category', null);
        $objectManager->get(Registry::class)->unregister('current_category');

        parent::tearDown();
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'a')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$a.id$'], 'a1')]
    #[DataFixture(CategoryFixture::class, as: 'b')]
    #[DataFixture(
        ProductFixture::class,
        ['category_ids' => ['$a.id$', '$a1.id$', '$b.id$']],
        'product'
    )]
    public function testTheDeepestAssignedCategoryUnderTheStoresRoot(): void
    {
        $this->assertSame($this->id('a1'), $this->schemaCategory());
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'a')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$a.id$'], 'a1')]
    #[DataFixture(CategoryFixture::class, as: 'b')]
    #[DataFixture(
        ProductFixture::class,
        ['category_ids' => ['$a.id$', '$a1.id$', '$b.id$']],
        'product'
    )]
    public function testTheCategoryInTheUrlWhenItIsOneOfTheProducts(): void
    {
        $this->inUrl('b');

        $this->assertSame($this->id('b'), $this->schemaCategory());
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'a')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$a.id$'], 'a1')]
    #[DataFixture(CategoryFixture::class, as: 'c')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$a.id$', '$a1.id$']], 'product')]
    public function testACategoryInTheUrlThatIsNotTheProductsIsIgnored(): void
    {
        $this->inUrl('c');

        $this->assertSame($this->id('a1'), $this->schemaCategory());
    }

    /**
     * The category last browsed reaches the registry, not the URL.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'a')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$a.id$'], 'a1')]
    #[DataFixture(CategoryFixture::class, as: 'b')]
    #[DataFixture(
        ProductFixture::class,
        ['category_ids' => ['$a.id$', '$a1.id$', '$b.id$']],
        'product'
    )]
    public function testTheCategoryLastBrowsedIsNotUsed(): void
    {
        $category = Bootstrap::getObjectManager()->get(\Magento\Catalog\Api\CategoryRepositoryInterface::class)
            ->get($this->id('b'));
        Bootstrap::getObjectManager()->get(Registry::class)->register('current_category', $category);

        $this->assertSame($this->id('a1'), $this->schemaCategory());
    }

    /**
     * A category in another root, another website's tree, is never chosen, however deep.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'a')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => 1], 'otherRoot')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$otherRoot.id$'], 'other')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$other.id$'], 'otherDeep')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$a.id$', '$otherDeep.id$']], 'product')]
    public function testACategoryOutsideTheStoresRootIsNeverChosen(): void
    {
        $this->assertSame($this->id('a'), $this->schemaCategory());
    }

    /**
     * Even when it is in the URL.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'a')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => 1], 'otherRoot')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$otherRoot.id$'], 'other')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$a.id$', '$other.id$']], 'product')]
    public function testACategoryOutsideTheStoresRootInTheUrlIsIgnored(): void
    {
        $this->inUrl('other');

        $this->assertSame($this->id('a'), $this->schemaCategory());
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'a')]
    #[DataFixture(CategoryFixture::class, as: 'b')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$b.id$', '$a.id$']], 'product')]
    public function testTiesGoToTheLowestId(): void
    {
        $this->assertSame(min($this->id('a'), $this->id('b')), $this->schemaCategory());
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAProductInNoCategoryHasNone(): void
    {
        $this->assertSame(0, $this->schemaCategory());
    }

    /**
     * The category SchemaCategory chooses for the fixture product, in the default store view.
     *
     * @return int
     */
    private function schemaCategory(): int
    {
        $objectManager = Bootstrap::getObjectManager();
        $product = $objectManager->get(ProductRepositoryInterface::class)->getById($this->id('product'));
        $storeId = (int) $objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        return $objectManager->get(SchemaCategory::class)->forProduct($product, $storeId);
    }

    /**
     * Put a fixture category in the URL, as a category-path product URL does.
     *
     * @param string $fixture
     * @return void
     */
    private function inUrl(string $fixture): void
    {
        Bootstrap::getObjectManager()->get(RequestInterface::class)->setParam('category', $this->id($fixture));
    }

    /**
     * A fixture's ID.
     *
     * @param string $fixture
     * @return int
     */
    private function id(string $fixture): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
    }
}
