<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Sitemap\Model\ResourceModel\Catalog\Product as CoreProductResource;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\ResourceModel\Sitemap\ProductStream;
use MageOS\Seo\Test\Integration\Model\Sitemap\Fixture\ExcludingProductResource;
use PHPUnit\Framework\TestCase;

/**
 * ProductStream reads, a page at a time, exactly the products core's sitemap product resource model
 * reads in one go — with the same URL, last-modified date and images, which is all a sitemap row is
 * built from.
 *
 * Every comparison is against core's resource model on the same installation, so it holds on every
 * version CI runs: that is what makes one query right for all of them.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ProductStreamTest extends TestCase
{
    /**
     * Whatever the page size — one, a few, or more than the catalogue — the same products as core,
     * each once; the disabled and the not-visible product in neither.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'p1')]
    #[DataFixture(ProductFixture::class, as: 'p2')]
    #[DataFixture(ProductFixture::class, as: 'p3')]
    #[DataFixture(ProductFixture::class, as: 'p4')]
    #[DataFixture(ProductFixture::class, as: 'p5')]
    #[DataFixture(ProductFixture::class, ['status' => Status::STATUS_DISABLED], as: 'disabled')]
    #[DataFixture(ProductFixture::class, ['visibility' => Visibility::VISIBILITY_NOT_VISIBLE], as: 'hidden')]
    public function testListsWhatCoresProductResourceLists(): void
    {
        $core = $this->normalised($this->coreCollection());

        foreach (['p1', 'p2', 'p3', 'p4', 'p5'] as $fixture) {
            $this->assertArrayHasKey($this->fixtureId($fixture), $core, 'The comparison needs the catalogue in it.');
        }
        $this->assertArrayNotHasKey($this->fixtureId('disabled'), $core);
        $this->assertArrayNotHasKey($this->fixtureId('hidden'), $core);

        foreach ([1, 2, 3, ProductStream::PAGE_SIZE] as $pageSize) {
            $this->assertSame(
                $core,
                $this->normalised($this->stream()->stream($this->storeId(), $pageSize)),
                'Page size ' . $pageSize . ' read something other than core reads.'
            );
        }
    }

    /**
     * Images as core builds them under each of the store's image policies.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_with_image.php
     * @return void
     */
    public function testImagesAsCoreBuildsThem(): void
    {
        foreach (['all', 'base', 'none'] as $policy) {
            Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
                ->setValue('sitemap/product/image_include', $policy, 'store', 'default');

            $core = $this->normalised($this->coreCollection());
            $ours = $this->normalised($this->stream()->stream($this->storeId()));

            $productId = (string) Bootstrap::getObjectManager()
                ->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)
                ->get('simple')
                ->getId();
            $this->assertArrayHasKey($productId, $core);
            if ($policy !== 'none') {
                $this->assertNotNull($core[$productId][0]['images'], 'The fixture product has images.');
            }
            $this->assertSame($core, $ours, 'Images differ from core\'s under policy "' . $policy . '".');
        }
    }

    /**
     * A plugin on core's select hook shapes this query too — and cannot break its paging.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'p1')]
    #[DataFixture(ProductFixture::class, as: 'p2')]
    #[DataFixture(ProductFixture::class, as: 'p3')]
    public function testCoresSelectHookShapesTheStream(): void
    {
        Bootstrap::getObjectManager()->configure([
            'preferences' => [CoreProductResource::class => ExcludingProductResource::class],
        ]);
        ExcludingProductResource::$excludedId = (int) $this->fixtureId('p2');

        $streamed = $this->normalised($this->stream()->stream($this->storeId(), 1));

        $this->assertArrayNotHasKey($this->fixtureId('p2'), $streamed, 'The hook\'s exclusion applies.');
        $this->assertArrayHasKey($this->fixtureId('p1'), $streamed, 'The hook\'s ordering does not stop paging.');
        $this->assertArrayHasKey($this->fixtureId('p3'), $streamed);
    }

    /**
     * Core's resource model, as core's generator uses it: fresh, for one read.
     *
     * @return array<DataObject>
     */
    private function coreCollection(): array
    {
        $collection = Bootstrap::getObjectManager()
            ->create(CoreProductResource::class)
            ->getCollection($this->storeId());
        $this->assertIsArray($collection);

        return $collection;
    }

    /**
     * @return ProductStream
     */
    private function stream(): ProductStream
    {
        return Bootstrap::getObjectManager()->create(ProductStream::class);
    }

    /**
     * Products as comparable arrays, keyed by ID, each ID's products listed — so a product read
     * twice shows as two.
     *
     * Only what a sitemap item is built from (AbstractEntityProvider reads `id`, `url`,
     * `updated_at` and `images`), the images in full. The rest of core's object is not the same
     * across versions: before 2.4.9 its per-product gallery read leaves `store_id` on every product
     * under the "all" image policy, and from 2.4.9 it does not.
     *
     * @param iterable<DataObject> $products
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function normalised(iterable $products): array
    {
        $result = [];
        foreach ($products as $product) {
            $images = $product->getData('images');
            if ($images instanceof DataObject) {
                $images               = $images->getData();
                $images['collection'] = array_map(
                    static fn (DataObject $image): array => $image->getData(),
                    $images['collection']
                );
            }

            $result[(string) $product->getData('id')][] = [
                'url'        => $product->getData('url'),
                'updated_at' => $product->getData('updated_at'),
                'images'     => $images,
            ];
        }
        ksort($result);

        return $result;
    }

    /**
     * @param string $fixture
     * @return string
     */
    private function fixtureId(string $fixture): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
