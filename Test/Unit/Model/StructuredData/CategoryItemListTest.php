<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\PathResolver as CategoryPathResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\StructuredData\CategoryItemList;
use PHPUnit\Framework\TestCase;

/**
 * Deliberately NOT tagged magento-generated, unlike the repository tests.
 *
 * Those double a generated *Factory directly, which cannot be built where the class does not
 * exist. This one doubles ConfigRepository, whose constructor merely type-hints such a factory —
 * and a disabled-constructor stub never re-emits that signature, so nothing forces the class to
 * resolve. Tagging it anyway would drop CategoryItemList out of the mutation run for a
 * restriction it does not have.
 */
class CategoryItemListTest extends TestCase
{
    /**
     * Products the layer collection yields, as [name, url, small_image, thumbnail].
     *
     * @var array<int, mixed[]>
     */
    private array $products = [];

    /**
     * Whether the collection reports itself as loaded when asked for its page size.
     *
     * @var int
     */
    private int $pageSize = 12;

    /**
     * @var int
     */
    private int $currentPage = 1;

    /**
     * Category config row the repository returns.
     *
     * @var mixed[]
     */
    private array $categoryConfig = [];

    /**
     * @var bool
     */
    private bool $globallyEnabled = true;

    /**
     * @var int
     */
    private int $maximum = 100;

    protected function setUp(): void
    {
        $this->products        = [];
        $this->pageSize        = 12;
        $this->currentPage     = 1;
        $this->categoryConfig  = [];
        $this->globallyEnabled = true;
        $this->maximum         = 100;
    }

    public function testBuildsAListItemPerProductOnThePage(): void
    {
        $this->products = [
            ['name' => 'Alpha', 'url' => 'https://example.com/alpha.html'],
            ['name' => 'Bravo', 'url' => 'https://example.com/bravo.html'],
        ];

        $schema = $this->itemList()->build();

        $this->assertSame('ItemList', $schema['@type']);
        $this->assertSame(2, $schema['numberOfItems']);
        $this->assertSame('Alpha', $schema['itemListElement'][0]['name']);
        $this->assertSame('https://example.com/bravo.html', $schema['itemListElement'][1]['url']);
    }

    public function testPositionsContinueAcrossPages(): void
    {
        // Page three of twelve per page starts at 25, so the node describes where these products
        // sit in the whole listing rather than restarting at 1 on every page.
        $this->products    = [['name' => 'Alpha', 'url' => 'https://example.com/alpha.html']];
        $this->pageSize    = 12;
        $this->currentPage = 3;

        $schema = $this->itemList()->build();

        $this->assertSame(25, $schema['itemListElement'][0]['position']);
    }

    public function testTheConfiguredMaximumCapsTheListWithoutChangingThePage(): void
    {
        $this->products = [
            ['name' => 'Alpha', 'url' => 'https://example.com/a.html'],
            ['name' => 'Bravo', 'url' => 'https://example.com/b.html'],
            ['name' => 'Charlie', 'url' => 'https://example.com/c.html'],
        ];
        $this->maximum = 2;

        $schema = $this->itemList()->build();

        $this->assertSame(2, $schema['numberOfItems']);
        $this->assertSame(['Alpha', 'Bravo'], array_column($schema['itemListElement'], 'name'));
    }

    public function testAnEmptyListingEmitsNothing(): void
    {
        $this->assertSame([], $this->itemList()->build());
    }

    public function testACategoryCanTurnTheListOff(): void
    {
        $this->products       = [['name' => 'Alpha', 'url' => 'https://example.com/a.html']];
        $this->categoryConfig = ['item_list_enabled' => 0];

        $this->assertSame([], $this->itemList()->build(), 'The category setting wins over global.');
    }

    public function testACategoryCanTurnTheListOnWhereGlobalIsOff(): void
    {
        $this->products        = [['name' => 'Alpha', 'url' => 'https://example.com/a.html']];
        $this->categoryConfig  = ['item_list_enabled' => 1];
        $this->globallyEnabled = false;

        $this->assertNotSame([], $this->itemList()->build());
    }

    public function testTheGlobalSettingAppliesWhenTheCategoryHasNoOpinion(): void
    {
        $this->products        = [['name' => 'Alpha', 'url' => 'https://example.com/a.html']];
        $this->globallyEnabled = false;

        $this->assertSame([], $this->itemList()->build());
    }

    public function testPlaceholderImagesAreNotPublished(): void
    {
        $this->products = [
            ['name' => 'Alpha', 'url' => 'https://example.com/a.html', 'small_image' => 'no_selection'],
        ];

        $schema = $this->itemList()->build();

        $this->assertArrayNotHasKey('image', $schema['itemListElement'][0]);
    }

    public function testImagesAreResolvedAgainstTheStoreMediaUrl(): void
    {
        $this->products = [
            ['name' => 'Alpha', 'url' => 'https://example.com/a.html', 'small_image' => '/a/l/alpha.jpg'],
        ];

        $schema = $this->itemList()->build();

        $this->assertSame(
            'https://example.com/media/catalog/product/a/l/alpha.jpg',
            $schema['itemListElement'][0]['image']
        );
    }

    public function testTheThumbnailIsUsedWhenThereIsNoListImage(): void
    {
        $this->products = [
            ['name' => 'Alpha', 'url' => 'https://example.com/a.html', 'thumbnail' => '/a/l/thumb.jpg'],
        ];

        $schema = $this->itemList()->build();

        $this->assertSame(
            'https://example.com/media/catalog/product/a/l/thumb.jpg',
            $schema['itemListElement'][0]['image']
        );
    }

    public function testNothingIsEmittedOutsideACategoryPage(): void
    {
        $layer = $this->createStub(Layer::class);
        $layer->method('getCurrentCategory')->willReturn(null);

        $layerResolver = $this->createStub(LayerResolver::class);
        $layerResolver->method('get')->willReturn($layer);

        $this->assertSame([], $this->itemList($layerResolver)->build());
    }

    /**
     * The builder over a layer whose collection is already loaded, as it is at end of body.
     *
     * @param LayerResolver|null $layerResolver
     * @return CategoryItemList
     */
    private function itemList(?LayerResolver $layerResolver = null): CategoryItemList
    {
        $configRepository = $this->createStub(CategoryConfigRepository::class);
        $configRepository->method('getForCategory')->willReturnCallback(
            fn (): array => $this->categoryConfig
        );

        $pathResolver = $this->createStub(CategoryPathResolver::class);
        $pathResolver->method('forCategory')->willReturn(['1', '2', '5']);

        $seoConfig = $this->createStub(Config::class);
        $seoConfig->method('isCategoryItemListEnabled')->willReturnCallback(
            fn (): bool => $this->globallyEnabled
        );
        $seoConfig->method('getCategoryItemListMax')->willReturnCallback(fn (): int => $this->maximum);

        return new CategoryItemList(
            $layerResolver ?? $this->layerResolver(),
            $this->storeManager(),
            $configRepository,
            $pathResolver,
            $seoConfig
        );
    }

    /**
     * A layer holding the current category and a loaded product collection.
     *
     * @return LayerResolver
     */
    private function layerResolver(): LayerResolver
    {
        $category = $this->createStub(CategoryInterface::class);
        $category->method('getId')->willReturn(5);

        $collection = $this->createStub(Collection::class);
        $collection->method('getPageSize')->willReturnCallback(fn (): int => $this->pageSize);
        $collection->method('getCurPage')->willReturnCallback(fn (): int => $this->currentPage);
        $collection->method('getIterator')->willReturnCallback(
            fn (): \ArrayIterator => new \ArrayIterator($this->productModels())
        );

        $layer = $this->createStub(Layer::class);
        $layer->method('getCurrentCategory')->willReturn($category);
        $layer->method('getProductCollection')->willReturn($collection);

        $layerResolver = $this->createStub(LayerResolver::class);
        $layerResolver->method('get')->willReturn($layer);

        return $layerResolver;
    }

    /**
     * The set products as models, the way a loaded collection hands them over.
     *
     * @return Product[]
     */
    private function productModels(): array
    {
        return array_map(
            function (array $row): Product {
                // A stub rather than a constructor-less instance: getProductUrl() goes through
                // the product's URL model, which such an instance does not have.
                $product = $this->createStub(Product::class);
                $product->method('getProductUrl')->willReturn($row['url']);
                $product->method('getName')->willReturn($row['name']);
                $product->method('getData')->willReturnCallback(
                    static fn (string $key): ?string => $row[$key] ?? null
                );

                return $product;
            },
            $this->products
        );
    }

    /**
     * A store manager whose store answers for the media base URL.
     *
     * @return StoreManagerInterface
     */
    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/media/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }
}
