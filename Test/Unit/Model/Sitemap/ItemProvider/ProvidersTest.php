<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap\ItemProvider;

use Magento\Framework\DataObject;
use Magento\Sitemap\Model\ItemProvider\CategoryConfigReader;
use Magento\Sitemap\Model\ItemProvider\CmsPageConfigReader;
use Magento\Sitemap\Model\ItemProvider\ConfigReaderInterface;
use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface as CoreItemProviderInterface;
use Magento\Sitemap\Model\ItemProvider\ProductConfigReader;
use Magento\Sitemap\Model\ItemProvider\StoreUrlConfigReader;
use Magento\Sitemap\Model\ResourceModel\Catalog\Batch\Product as StreamingProductResource;
use Magento\Sitemap\Model\ResourceModel\Catalog\Batch\ProductFactory as StreamingProductFactory;
use Magento\Sitemap\Model\ResourceModel\Catalog\Category as CategoryResource;
use Magento\Sitemap\Model\ResourceModel\Catalog\CategoryFactory;
use Magento\Sitemap\Model\ResourceModel\Catalog\Product as ProductResource;
use Magento\Sitemap\Model\ResourceModel\Catalog\ProductFactory;
use Magento\Sitemap\Model\ResourceModel\Cms\Page as PageResource;
use Magento\Sitemap\Model\ResourceModel\Cms\PageFactory;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\ItemProvider\Category;
use MageOS\Seo\Model\Sitemap\ItemProvider\CmsPage;
use MageOS\Seo\Model\Sitemap\ItemProvider\Composite;
use MageOS\Seo\Model\Sitemap\ItemProvider\Product;
use MageOS\Seo\Model\Sitemap\ItemProvider\StoreUrl;
use MageOS\Seo\Model\Sitemap\SitemapItem;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The mapping from core's sitemap resource rows to items. That the result writes the same sitemap
 * as core's own providers is covered end to end by
 * Test/Integration/Model/Sitemap/ItemProviderWiringTest.
 *
 * The resource and item factories are Magento's generated classes, so this test needs an
 * installation to have generated them. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class ProvidersTest extends TestCase
{
    public function testARowBecomesAnItemCarryingItsEntity(): void
    {
        $images = new DataObject(['title' => 'Shirts']);
        $item   = $this->only($this->category([
            new DataObject(['id' => '7', 'url' => 'shirts.html', 'updated_at' => '2026-09-01', 'images' => $images]),
        ])->getItems(1));

        $this->assertSame('shirts.html', $item->getUrl());
        $this->assertSame('2026-09-01', $item->getUpdatedAt());
        $this->assertSame($images, $item->getImages());
        $this->assertSame('0.5', $item->getPriority());
        $this->assertSame('weekly', $item->getChangeFrequency());
        $this->assertSame(SitemapItemInterface::ENTITY_CATEGORY, $item->getEntityType());
        $this->assertSame(7, $item->getEntityId());
    }

    public function testAStoreViewThatDoesNotExistListsNothing(): void
    {
        // Core's resource models answer false rather than an empty list.
        $this->assertSame([], $this->category(false)->getItems(99));
        $this->assertSame([], iterator_to_array($this->category(false)->iterateItems(99), false));
    }

    public function testEachProviderSaysWhereItsItemsAreFiled(): void
    {
        $this->assertSame(ItemProviderInterface::TYPE_CATEGORIES, $this->category([])->getType());
        $this->assertSame(ItemProviderInterface::TYPE_PAGES, $this->cmsPage()->getType());
        $this->assertSame(ItemProviderInterface::TYPE_PAGES, $this->storeUrl()->getType());
        $this->assertSame(ItemProviderInterface::TYPE_PRODUCTS, $this->product([], [])->getType());
    }

    public function testCmsPagesAreFiledAsCmsPages(): void
    {
        $item = $this->only($this->cmsPage()->getItems(1));

        $this->assertSame(SitemapItemInterface::ENTITY_CMS_PAGE, $item->getEntityType());
        $this->assertSame(3, $item->getEntityId());
    }

    public function testTheHomePageIsTheStoreWithNoEntityId(): void
    {
        $item = $this->only($this->storeUrl()->getItems(1));

        $this->assertSame('', $item->getUrl());
        $this->assertNull($item->getUpdatedAt());
        $this->assertNull($item->getImages());
        $this->assertSame(SitemapItemInterface::ENTITY_STORE, $item->getEntityType());
        $this->assertNull($item->getEntityId());
    }

    public function testProductsListFromCoresStandardResourceAndStreamFromItsBatchResource(): void
    {
        $provider = $this->product(
            [new DataObject(['id' => '1', 'url' => 'listed.html'])],
            [new DataObject(['id' => '2', 'url' => 'streamed.html'])]
        );

        $this->assertSame('listed.html', $this->only($provider->getItems(1))->getUrl());
        $this->assertSame('streamed.html', $this->only($provider->iterateItems(1))->getUrl());
    }

    public function testTheCompositeHandsOutItsProvidersByTheNamesTheyWereRegisteredUnder(): void
    {
        $ours      = $this->storeUrl();
        $elsewhere = $this->createStub(CoreItemProviderInterface::class);

        $composite = new Composite(['storeUrlProvider' => $ours, 'vendorProvider' => $elsewhere]);

        $this->assertSame(['storeUrlProvider' => $ours, 'vendorProvider' => $elsewhere], $composite->getProviders());
    }

    public function testTheCompositeStillListsEveryItemForCoresGenerator(): void
    {
        $elsewhere = $this->createStub(CoreItemProviderInterface::class);
        $elsewhere->method('getItems')->willReturn([new SitemapItem('vendor.html', '0.5', 'daily')]);

        $items = (new Composite(['store' => $this->storeUrl(), 'vendor' => $elsewhere]))->getItems(1);

        $this->assertSame(['', 'vendor.html'], array_map(static fn ($item) => $item->getUrl(), $items));
    }

    /**
     * @param iterable<SitemapItemInterface> $items
     * @return SitemapItemInterface
     */
    private function only(iterable $items): SitemapItemInterface
    {
        $items = \is_array($items) ? $items : iterator_to_array($items, false);
        $this->assertCount(1, $items);

        return reset($items);
    }

    /**
     * @param DataObject[]|false $rows
     * @return Category
     */
    private function category(array|false $rows): Category
    {
        $resource = $this->createStub(CategoryResource::class);
        $resource->method('getCollection')->willReturn($rows);
        $factory = $this->createStub(CategoryFactory::class);
        $factory->method('create')->willReturn($resource);

        return new Category($this->configReader(CategoryConfigReader::class), $this->itemFactory(), $factory);
    }

    /**
     * @return CmsPage
     */
    private function cmsPage(): CmsPage
    {
        $resource = $this->createStub(PageResource::class);
        $resource->method('getCollection')->willReturn([new DataObject(['id' => '3', 'url' => 'about-us'])]);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($resource);

        return new CmsPage($this->configReader(CmsPageConfigReader::class), $this->itemFactory(), $factory);
    }

    /**
     * @return StoreUrl
     */
    private function storeUrl(): StoreUrl
    {
        return new StoreUrl($this->configReader(StoreUrlConfigReader::class), $this->itemFactory());
    }

    /**
     * @param DataObject[] $listed Rows of core's standard product resource
     * @param DataObject[] $streamed Rows of core's batch product resource
     * @return Product
     */
    private function product(array $listed, array $streamed): Product
    {
        $standard = $this->createStub(ProductResource::class);
        $standard->method('getCollection')->willReturn($listed);
        $standardFactory = $this->createStub(ProductFactory::class);
        $standardFactory->method('create')->willReturn($standard);

        $batch = $this->createStub(StreamingProductResource::class);
        $batch->method('getCollection')->willReturnCallback(
            static function () use ($streamed): \Generator {
                yield from $streamed;
            }
        );
        $batchFactory = $this->createStub(StreamingProductFactory::class);
        $batchFactory->method('create')->willReturn($batch);

        return new Product(
            $this->configReader(ProductConfigReader::class),
            $this->itemFactory(),
            $standardFactory,
            $batchFactory
        );
    }

    /**
     * One of core's config readers, answering a priority of 0.5 and a weekly change frequency.
     *
     * @template T of ConfigReaderInterface
     * @param class-string<T> $class
     * @return T
     */
    private function configReader(string $class): ConfigReaderInterface
    {
        $reader = $this->createStub($class);
        $reader->method('getPriority')->willReturn('0.5');
        $reader->method('getChangeFrequency')->willReturn('weekly');

        return $reader;
    }

    /**
     * An item factory that builds real items.
     *
     * @return SitemapItemFactory
     */
    private function itemFactory(): SitemapItemFactory
    {
        $factory = $this->createStub(SitemapItemFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data): SitemapItem => new SitemapItem(
                $data['url'],
                $data['priority'],
                $data['changeFrequency'],
                $data['updatedAt'] ?? null,
                $data['images'] ?? null,
                $data['entityType'] ?? null,
                $data['entityId'] ?? null
            )
        );

        return $factory;
    }
}
