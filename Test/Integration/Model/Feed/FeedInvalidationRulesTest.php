<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Feed;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\FlagManager;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\WebsiteFactory;
use Magento\Store\Test\Fixture\Group as GroupFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\Store\Test\Fixture\Website as WebsiteFixture;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use PHPUnit\Framework\TestCase;

/**
 * Which real saves queue which feed rebuilds.
 *
 * Database isolation is disabled because creating a store view is not transactional;
 * the data fixtures revert themselves and the pending flags are cleared around each check.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class FeedInvalidationRulesTest extends TestCase
{
    private const JSONL_ENABLED = 'mageos_seo_general/llms_txt/jsonl_enabled';

    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

    /**
     * Remove the CMS pages the test created and leave no pending rebuild requests behind.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $pageRepository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // The test deleted it itself.
            }
        }
        $this->createdPageIds = [];

        $this->clearPending();
    }

    /**
     * Product saves queue llms.jsonl, and hreflang / llms only for the changes they depend on.
     *
     * @return void
     */
    #[Config(self::JSONL_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testProductSavesQueueOnlyTheFeedsTheChangeAffects(): void
    {
        $sku        = (string) $this->fixture('product')->getSku();
        $categoryId = (int) $this->fixture('category')->getId();

        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_JSONL],
            fn () => $this->saveProduct($sku, ['name' => 'Renamed product'])
        );
        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_JSONL, FeedRegenerator::GROUP_HREFLANG],
            fn () => $this->saveProduct($sku, ['url_key' => 'renamed-product-' . uniqid()])
        );
        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_JSONL],
            fn () => $this->saveProduct($sku, ['category_ids' => [$categoryId]])
        );
    }

    /**
     * A new product can change every feed.
     *
     * @return void
     */
    #[Config(self::JSONL_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testANewProductQueuesEveryFeed(): void
    {
        $productFixture = Bootstrap::getObjectManager()->get(ProductFixture::class);
        $created        = null;

        try {
            $this->assertQueuedBy(
                FeedRegenerator::GROUPS,
                function () use ($productFixture, &$created): void {
                    $created = $productFixture->apply();
                }
            );
        } finally {
            if ($created !== null) {
                $productFixture->revert($created);
            }
        }
    }

    /**
     * Category saves always queue llms, and hreflang only for URL-relevant changes.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testCategorySavesQueueOnlyTheFeedsTheChangeAffects(): void
    {
        $categoryId = (int) $this->fixture('category')->getId();

        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_LLMS],
            fn () => $this->saveCategory($categoryId, ['name' => 'Renamed category'])
        );
        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_HREFLANG],
            fn () => $this->saveCategory($categoryId, ['url_key' => 'renamed-category-' . uniqid()])
        );
    }

    /**
     * CMS page saves queue hreflang only for URL-relevant changes.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testCmsPageSavesQueueHreflangOnlyForUrlChanges(): void
    {
        $pageId = $this->createPage();

        $this->assertQueuedBy([], fn () => $this->savePage($pageId, ['title' => 'Renamed page']));
        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_HREFLANG],
            fn () => $this->savePage($pageId, ['identifier' => 'renamed-page-' . uniqid()])
        );
    }

    /**
     * Moving a category changes its URL and the shape of the llms category tree, but not the
     * product URLs in llms.jsonl.
     *
     * @return void
     */
    #[Config(self::JSONL_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(CategoryFixture::class, as: 'new_parent')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testMovingACategoryQueuesLlmsAndTheHreflangSitemap(): void
    {
        $categoryId  = (int) $this->fixture('category')->getId();
        $newParentId = (int) $this->fixture('new_parent')->getId();

        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_HREFLANG],
            static function () use ($categoryId, $newParentId): void {
                Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class)
                    ->get($categoryId)
                    ->move($newParentId, 0);
            }
        );
    }

    /**
     * Mass attribute updates write to the EAV tables without saving the products, so only the
     * attribute codes say what can have changed.
     *
     * @return void
     */
    #[Config(self::JSONL_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testMassAttributeUpdatesQueueTheFeedsTheAttributesAppearIn(): void
    {
        $productId = (int) $this->fixture('product')->getId();

        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_JSONL, FeedRegenerator::GROUP_HREFLANG],
            fn () => $this->massUpdateAttributes([$productId], ['status' => Status::STATUS_DISABLED])
        );
        // The llms documents list categories and product counts, which no attribute value changes.
        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_JSONL],
            fn () => $this->massUpdateAttributes([$productId], ['meta_title' => 'Mass updated'])
        );
    }

    /**
     * A mass website assignment change moves products in and out of a store view's catalogue,
     * and with them the category product counts in llms.txt.
     *
     * @return void
     */
    #[Config(self::JSONL_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAMassWebsiteChangeQueuesEveryFeed(): void
    {
        $productId = (int) $this->fixture('product')->getId();
        $websiteId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')->getWebsiteId();

        $this->assertQueuedBy(
            FeedRegenerator::GROUPS,
            fn () => Bootstrap::getObjectManager()->create(ProductAction::class)
                ->updateWebsites([$productId], [$websiteId], 'remove')
        );
    }

    /**
     * Deleting a store view changes the sitemap's alternate set, and its own feed files are
     * no longer served by anything.
     *
     * Three store views: the deleted one must leave two behind, or the sitemap becomes
     * unbuildable and the rebuild is (correctly) not queued at all.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(StoreFixture::class, as: 'third_store')]
    public function testDeletingAStoreViewQueuesTheSitemapAndRemovesItsFeedFiles(): void
    {
        $storeId = (int) $this->fixture('third_store')->getId();
        $storage = Bootstrap::getObjectManager()->create(FeedStorage::class);
        $storage->write('llms.txt', $storeId, 'stale');
        $this->assertSame('stale', $storage->read('llms.txt', $storeId));

        $this->assertQueuedBy([FeedRegenerator::GROUP_HREFLANG], fn () => $this->deleteStore($storeId));

        $this->assertNull($storage->read('llms.txt', $storeId), 'The store directory is gone.');
    }

    /**
     * A deleted website takes its store views with it, through a database-level cascade that
     * dispatches no store_delete event — so the website's own deletion has to queue the rebuild.
     *
     * @return void
     */
    #[DataFixture(WebsiteFixture::class, as: 'website')]
    #[DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], 'group')]
    #[DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$'], 'store')]
    public function testDeletingAWebsiteQueuesTheHreflangSitemap(): void
    {
        $websiteId = (int) $this->fixture('website')->getId();

        $this->assertQueuedBy([FeedRegenerator::GROUP_HREFLANG], fn () => $this->deleteWebsite($websiteId));
    }

    /**
     * Deleting a product takes it out of every feed.
     *
     * The entity is created here rather than by a class-level fixture: a fixture would try to
     * delete it again when it reverts.
     *
     * @return void
     */
    #[Config(self::JSONL_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testDeletingAProductQueuesEveryFeed(): void
    {
        $sku = (string) $this->create(ProductFixture::class)->getSku();

        $this->assertQueuedBy(
            FeedRegenerator::GROUPS,
            fn () => Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->deleteById($sku)
        );
    }

    /**
     * Deleting a category takes it out of the category tree and the sitemap.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testDeletingACategoryQueuesLlmsAndTheHreflangSitemap(): void
    {
        $categoryId = (int) $this->create(CategoryFixture::class)->getId();

        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_HREFLANG],
            fn () => Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class)
                ->deleteByIdentifier($categoryId)
        );
    }

    /**
     * Deleting a CMS page takes its URL out of the sitemap.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testDeletingACmsPageQueuesTheHreflangSitemap(): void
    {
        $pageId = $this->createPage();

        $this->assertQueuedBy(
            [FeedRegenerator::GROUP_HREFLANG],
            fn () => Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->deleteById($pageId)
        );
    }

    /**
     * With the default configuration on a single store view, llms.jsonl is disabled and the
     * hreflang sitemap cannot be built, so a product URL change queues nothing.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testFeedsNoStoreViewCanBuildAreNotQueued(): void
    {
        $sku = (string) $this->fixture('product')->getSku();

        $this->assertQueuedBy([], fn () => $this->saveProduct($sku, ['url_key' => 'single-store-' . uniqid()]));
    }

    /**
     * Assert exactly which feed groups a change queues.
     *
     * @param string[] $expectedGroups
     * @param callable $change
     * @return void
     */
    private function assertQueuedBy(array $expectedGroups, callable $change): void
    {
        $this->clearPending();
        $change();

        $pending = [];
        $flags   = Bootstrap::getObjectManager()->get(FlagManager::class);
        foreach (FeedRegenerator::GROUPS as $group) {
            if ($flags->getFlagData('mageos_seo_feed_pending_' . $group) !== null) {
                $pending[] = $group;
            }
        }
        sort($pending);
        sort($expectedGroups);

        $this->assertSame($expectedGroups, $pending);
    }

    /**
     * Remove every pending rebuild request.
     *
     * @return void
     */
    private function clearPending(): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        foreach (FeedRegenerator::GROUPS as $group) {
            $flags->deleteFlag('mageos_seo_feed_pending_' . $group);
        }
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
     * Load a category at the default scope, change it and save it through the repository.
     *
     * @param int $categoryId
     * @param array<string, mixed> $changes
     * @return void
     */
    private function saveCategory(int $categoryId, array $changes): void
    {
        $repository = Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class);
        $category   = $repository->get($categoryId, 0);
        $category->addData($changes);
        $repository->save($category);
    }

    /**
     * Load a CMS page, change it and save it through the repository.
     *
     * @param int $pageId
     * @param array<string, mixed> $changes
     * @return void
     */
    private function savePage(int $pageId, array $changes): void
    {
        $repository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        $page       = $repository->getById($pageId);
        $page->addData($changes);
        $repository->save($page);
    }

    /**
     * Run a mass attribute update at the default scope, as the admin mass actions do.
     *
     * @param int[] $productIds
     * @param array<string, mixed> $attributes
     * @return void
     */
    private function massUpdateAttributes(array $productIds, array $attributes): void
    {
        Bootstrap::getObjectManager()->create(ProductAction::class)
            ->updateAttributes($productIds, $attributes, 0);
    }

    /**
     * Delete a website through its resource; core cascades its groups and store views.
     *
     * @param int $websiteId
     * @return void
     */
    private function deleteWebsite(int $websiteId): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource      = $objectManager->get(WebsiteResource::class);

        $website = $objectManager->get(WebsiteFactory::class)->create();
        $resource->load($website, $websiteId);
        $resource->delete($website);
        $objectManager->get(StoreManagerInterface::class)->reinitStores();
    }

    /**
     * Delete a store view through its resource, as the admin controller and the fixtures do.
     *
     * @param int $storeId
     * @return void
     */
    private function deleteStore(int $storeId): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource      = $objectManager->get(StoreResource::class);

        $store = $objectManager->get(StoreFactory::class)->create();
        $resource->load($store, $storeId);
        $resource->delete($store);
        $objectManager->get(StoreManagerInterface::class)->reinitStores();
    }

    /**
     * Create an active CMS page and return its ID.
     *
     * Built here rather than with Magento\Cms\Test\Fixture\Page: that fixture only exists in
     * recent releases, and on the versions without it the framework falls back to loading the
     * class name as a legacy fixture file path and fails.
     *
     * @return int
     */
    private function createPage(): int
    {
        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-test-page-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO test page',
            PageInterface::CONTENT    => '<p>MageOS SEO test page</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;

        return $pageId;
    }

    /**
     * Apply a data fixture from the test body, for entities the test deletes itself.
     *
     * @param class-string $fixtureClass
     * @return \Magento\Framework\DataObject
     */
    private function create(string $fixtureClass): \Magento\Framework\DataObject
    {
        return Bootstrap::getObjectManager()->get($fixtureClass)->apply();
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
