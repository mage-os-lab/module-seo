<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Framework\Filesystem;
use Magento\Sitemap\Helper\Data as SitemapHelper;
use Magento\Sitemap\Model\ResourceModel\Sitemap\Collection;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Sitemap\Model\Source\Product\Image\IncludeImage;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #23: whether a sitemap a change rebuilds lists product images — so the name, the image
 * roles and the gallery are worth a rebuild — read from core's image policy per store view.
 *
 * The sitemap collection factory is Magento's generated class, so this test needs an installation
 * to have generated it. The mutation-testing run works from the module directory alone and
 * excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class RebuildableSitemapsTest extends TestCase
{
    /**
     * @return void
     */
    public function testImagesAreListedWhenOneRebuiltSitemapsStoreViewIncludesThem(): void
    {
        $this->assertTrue(
            $this->sitemaps([1 => IncludeImage::INCLUDE_NONE, 2 => IncludeImage::INCLUDE_BASE])->listsProductImages()
        );
        $this->assertTrue($this->sitemaps([1 => IncludeImage::INCLUDE_ALL])->listsProductImages());
        $this->assertFalse($this->sitemaps([1 => IncludeImage::INCLUDE_NONE])->listsProductImages());
        $this->assertFalse($this->sitemaps([])->listsProductImages(), 'No sitemap, no images.');
    }

    /**
     * A store view a change does not rebuild does not count.
     *
     * @return void
     */
    public function testOnlySitemapsAChangeRebuildsCount(): void
    {
        $this->assertFalse(
            $this->sitemaps([1 => IncludeImage::INCLUDE_ALL], rebuildOnChange: false)->listsProductImages()
        );
    }

    /**
     * Asked on every product save, so answered once per request, and again after a reset.
     *
     * @return void
     */
    public function testTheAnswerIsKeptUntilReset(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->exactly(2))->method('create')->willReturnCallback(
            fn () => $this->collection([$this->sitemap(1)])
        );
        $sitemaps = $this->sitemaps([1 => IncludeImage::INCLUDE_ALL], collectionFactory: $factory);

        $sitemaps->listsProductImages();
        $sitemaps->listsProductImages();
        $sitemaps->_resetState();
        $sitemaps->listsProductImages();
    }

    /**
     * Rebuildable sitemaps of active store views on this module's generator, one per store view,
     * with the given image policies.
     *
     * @param array<int,string> $policies store ID => IncludeImage::INCLUDE_*
     * @param bool $rebuildOnChange
     * @param CollectionFactory|null $collectionFactory
     * @return RebuildableSitemaps
     */
    private function sitemaps(
        array $policies,
        bool $rebuildOnChange = true,
        ?CollectionFactory $collectionFactory = null
    ): RebuildableSitemaps {
        if ($collectionFactory === null) {
            $collectionFactory = $this->createStub(CollectionFactory::class);
            $collectionFactory->method('create')->willReturnCallback(
                fn () => $this->collection(array_map(fn (int $id) => $this->sitemap($id), array_keys($policies)))
            );
        }

        $store = $this->createStub(StoreInterface::class);
        $store->method('getIsActive')->willReturn(true);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('isSitemapGeneratorEnabled')->willReturn(true);
        $config->method('isSitemapRebuildOnChangeEnabled')->willReturn($rebuildOnChange);

        $helper = $this->createStub(SitemapHelper::class);
        $helper->method('getProductImageIncludePolicy')->willReturnCallback(
            static fn ($storeId): string => $policies[(int) $storeId] ?? IncludeImage::INCLUDE_NONE
        );

        return new RebuildableSitemaps(
            $collectionFactory,
            $storeManager,
            $config,
            $this->createStub(Filesystem::class),
            $helper
        );
    }

    /**
     * A collection iterating over the given sitemaps.
     *
     * @param Sitemap[] $sitemaps
     * @return Collection
     */
    private function collection(array $sitemaps): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($sitemaps));

        return $collection;
    }

    /**
     * @param int $storeId
     * @return Sitemap
     */
    private function sitemap(int $storeId): Sitemap
    {
        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => $method === 'getStoreId' ? $storeId : null
        );

        return $sitemap;
    }
}
