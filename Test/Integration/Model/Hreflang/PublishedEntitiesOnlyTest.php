<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Hreflang;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use MageOS\Seo\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use PHPUnit\Framework\TestCase;

/**
 * Review finding S1: the hreflang sitemap and the head alternates listed entities a visitor
 * cannot reach.
 *
 * A url_rewrite row outlives the state of the thing it points at, so disabling a product,
 * deactivating a category or unpublishing a CMS page used to leave it advertised as an alternate.
 * These tests drive the real tables, because the defect is in the query.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class PublishedEntitiesOnlyTest extends TestCase
{
    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $pageRepository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // Already gone.
            }
        }
        $this->createdPageIds = [];
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testADisabledProductIsNotAnAlternate(): void
    {
        $product   = $this->fixture('product');
        $productId = (int) $product->getId();

        $this->assertNotSame(
            [],
            $this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_PRODUCT, $productId),
            'The enabled product has alternates to begin with.'
        );

        $this->saveProduct((string) $product->getSku(), ['status' => Status::STATUS_DISABLED]);

        $this->assertSame(
            [],
            $this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_PRODUCT, $productId),
            'A disabled product is not advertised as an alternate.'
        );
        $this->assertNotContains(
            $productId,
            $this->streamedEntityIds(UrlRewriteResource::TYPE_PRODUCT),
            'And it is gone from the sitemap stream too.'
        );
    }

    /**
     * Core deletes the rewrites of a product that becomes invisible, so this passes with or
     * without the visibility filter. It is kept as a statement of the expectation: if core ever
     * leaves those rows behind, the filter is what has to catch it.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAProductHiddenFromTheCatalogueIsNotAnAlternate(): void
    {
        $product = $this->fixture('product');

        $this->saveProduct((string) $product->getSku(), ['visibility' => Visibility::VISIBILITY_NOT_VISIBLE]);

        $this->assertSame(
            [],
            $this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_PRODUCT, (int) $product->getId())
        );
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testAnInactiveCategoryIsNotAnAlternate(): void
    {
        $categoryId = (int) $this->fixture('category')->getId();

        $this->assertNotSame(
            [],
            $this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_CATEGORY, $categoryId),
            'The active category has alternates to begin with.'
        );

        $repository = Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class);
        $category   = $repository->get($categoryId);
        $category->setIsActive(false);
        $repository->save($category);

        $this->assertSame(
            [],
            $this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_CATEGORY, $categoryId)
        );
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testAnUnpublishedCmsPageIsNotAnAlternate(): void
    {
        $pageId = $this->createPage(true);

        $this->assertNotSame(
            [],
            $this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_CMS_PAGE, $pageId),
            'The published page has alternates to begin with.'
        );

        $repository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        $page       = $repository->getById($pageId);
        $page->setIsActive(false);
        $repository->save($page);

        $this->assertSame(
            [],
            $this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_CMS_PAGE, $pageId)
        );
    }

    /**
     * A page is only an alternate in the store views it is assigned to.
     *
     * Core already writes rewrites per assigned store, so this passes with or without the
     * filter — its job is to catch the opposite failure: the cms_page_store join added for the
     * is_active check must not widen or duplicate the store scope.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testACmsPageIsOnlyAnAlternateWhereItIsAssigned(): void
    {
        $defaultStoreId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')->getId();
        $pageId = $this->createPage(true, [$defaultStoreId]);

        $this->assertSame(
            [$defaultStoreId],
            array_keys($this->fetcher()->fetchForEntity(UrlRewriteResource::TYPE_CMS_PAGE, $pageId))
        );
    }

    /**
     * Entity IDs the sitemap stream yields for a type, across the active store views.
     *
     * @param string $entityType
     * @return int[]
     */
    private function streamedEntityIds(string $entityType): array
    {
        $storeIds = [];
        foreach (Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStores() as $store) {
            if ($store->getIsActive()) {
                $storeIds[] = (int) $store->getId();
            }
        }

        // The stream yields paths per entity, not IDs, so identify entities by their paths.
        $paths = [];
        foreach ($this->fetcher()->streamAllForType($entityType, $storeIds) as $entityPaths) {
            $paths[] = reset($entityPaths);
        }

        $ids = [];
        foreach ($paths as $path) {
            $ids[] = $path;
        }

        return $ids;
    }

    /**
     * @return UrlRewriteFetcher
     */
    private function fetcher(): UrlRewriteFetcher
    {
        return Bootstrap::getObjectManager()->create(UrlRewriteFetcher::class);
    }

    /**
     * Load a product at the default scope, change it and save it through the repository.
     *
     * @param string $sku
     * @param array<string, mixed> $changes
     * @return void
     */
    private function saveProduct(string $sku, array $changes): void
    {
        $repository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        $product    = $repository->get($sku, true, 0, true);
        $product->addData($changes);
        $repository->save($product);
    }

    /**
     * Create a CMS page and return its ID.
     *
     * @param bool $isActive
     * @param int[]|null $storeIds Null assigns it to all store views
     * @return int
     */
    private function createPage(bool $isActive, ?array $storeIds = null): int
    {
        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-s1-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO S1 page',
            PageInterface::CONTENT    => '<p>S1</p>',
            PageInterface::IS_ACTIVE  => $isActive ? 1 : 0,
            'stores'                  => $storeIds ?? [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;

        return $pageId;
    }

    /**
     * An entity created by a data fixture.
     *
     * @param string $name
     * @return \Magento\Framework\DataObject
     */
    private function fixture(string $name): \Magento\Framework\DataObject
    {
        return DataFixtureStorageManager::getStorage()->get($name);
    }
}
