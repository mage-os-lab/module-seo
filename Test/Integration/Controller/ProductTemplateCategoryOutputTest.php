<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Category\ConfigRepository;

/**
 * Review low item, decision D9b: a product page's schema template comes from the category in its
 * URL, else from the product's deepest category in the store's tree.
 *
 * The product is in two categories at the same depth, one set to the Book template and one to Art
 * and Craft, which the node's @type tells apart.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ProductTemplateCategoryOutputTest extends AbstractController
{
    use ProductPageOutput;

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
    #[DataFixture(CategoryFixture::class, as: 'books')]
    #[DataFixture(CategoryFixture::class, as: 'art')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$books.id$', '$art.id$']], 'product')]
    public function testTheBooksCategoryInTheUrlChoosesBook(): void
    {
        $this->templates();

        $this->assertContains('Book', $this->types('books'));
    }

    /**
     * The same product through the other category's URL. One page per test: a product page
     * registers its product, and a second dispatch in one test would register it again.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'books')]
    #[DataFixture(CategoryFixture::class, as: 'art')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$books.id$', '$art.id$']], 'product')]
    public function testTheArtCategoryInTheUrlChoosesArtAndCraft(): void
    {
        $this->templates();

        $this->assertContains('VisualArtwork', $this->types('art'));
    }

    /**
     * Without a category in the URL, the deepest; here both are as deep, so the lower ID.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'books')]
    #[DataFixture(CategoryFixture::class, as: 'art')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$art.id$', '$books.id$']], 'product')]
    public function testWithoutACategoryInTheUrlTheLowestOfTheDeepest(): void
    {
        $this->templates();

        $expected = $this->id('books') < $this->id('art') ? 'Book' : 'VisualArtwork';
        $this->assertContains($expected, $this->types(null));
    }

    /**
     * Set the two categories' templates.
     *
     * @return void
     */
    private function templates(): void
    {
        $repository = Bootstrap::getObjectManager()->get(ConfigRepository::class);
        $repository->save($this->id('books'), ['schema_template' => 'Book']);
        $repository->save($this->id('art'), ['schema_template' => 'ArtAndCraft']);
    }

    /**
     * The product node's types, the page reached through a category's URL or directly.
     *
     * @param string|null $category
     * @return string[]
     */
    private function types(?string $category): array
    {
        $path = 'catalog/product/view/id/' . $this->id('product');
        if ($category !== null) {
            $path .= '/category/' . $this->id($category);
        }
        $this->dispatch($path);

        return (array) ($this->productNode((string) $this->getResponse()->getBody())['@type'] ?? []);
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
