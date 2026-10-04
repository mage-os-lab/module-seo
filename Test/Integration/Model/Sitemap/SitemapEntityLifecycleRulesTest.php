<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\FlagManager;
use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\WebsiteFactory;
use Magento\Store\Test\Fixture\Group as GroupFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\Store\Test\Fixture\Website as WebsiteFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use PHPUnit\Framework\TestCase;

/**
 * Which creations, moves, mass actions and deletions queue which sitemap rebuilds: the wiring
 * SitemapInvalidationRulesTest does not exercise. Which saves count in detail is that test's.
 *
 * Every test has a sitemap a change would rebuild.
 *
 * Database isolation is disabled because creating a store view is not transactional;
 * the data fixtures revert themselves and the pending flags are cleared around each check.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class SitemapEntityLifecycleRulesTest extends TestCase
{
    /**
     * With the first build, which saving the test's sitemap entry queues: cleared around each check
     * like the rest, and never left pending for the next test — this class does not roll back.
     */
    private const SITEMAP_GROUPS = [
        'sitemap-pages',
        'sitemap-categories',
        'sitemap-products',
        'sitemap-*',
        'sitemaps-missing',
    ];

    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

    /**
     * The sitemap the running test made rebuildable.
     *
     * @var Sitemap|null
     */
    private ?Sitemap $sitemap = null;

    /**
     * Remove the CMS pages and the sitemap the test created and leave no pending rebuild requests
     * behind.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $pageRepository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds ?? [] as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // The test deleted it itself.
            }
        }
        $this->createdPageIds = [];

        if ($this->sitemap !== null) {
            Bootstrap::getObjectManager()->get(SitemapResource::class)->delete($this->sitemap);
            $this->sitemap = null;
        }
        $this->forgetRebuildableSitemaps();

        $this->clearPending();
    }

    /**
     * A new product is a new product URL.
     *
     * @return void
     */
    public function testANewProductQueuesTheProductsSitemap(): void
    {
        $this->aRebuildableSitemap();
        $productFixture = Bootstrap::getObjectManager()->get(ProductFixture::class);
        $created        = null;

        try {
            $this->assertQueuedBy(
                ['sitemap-products'],
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
     * Moving a category changes its URL.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'new_parent')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testMovingACategoryQueuesTheCategoriesSitemap(): void
    {
        $this->aRebuildableSitemap();
        $categoryId  = (int) $this->fixture('category')->getId();
        $newParentId = (int) $this->fixture('new_parent')->getId();

        $this->assertQueuedBy(
            ['sitemap-categories'],
            static function () use ($categoryId, $newParentId): void {
                Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class)
                    ->get($categoryId)
                    ->move($newParentId, 0);
            }
        );
    }

    /**
     * A mass website assignment change moves products in and out of a store view's catalogue.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAMassWebsiteChangeQueuesTheProductsSitemap(): void
    {
        $this->aRebuildableSitemap();
        $productId = (int) $this->fixture('product')->getId();
        $websiteId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')->getWebsiteId();

        $this->assertQueuedBy(
            ['sitemap-products'],
            fn () => Bootstrap::getObjectManager()->create(ProductAction::class)
                ->updateWebsites([$productId], [$websiteId], 'remove')
        );
    }

    /**
     * Deleting a store view changes the alternates in the other store views' sitemaps.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(StoreFixture::class, as: 'third_store')]
    public function testDeletingAStoreViewQueuesEverySitemapType(): void
    {
        $this->aRebuildableSitemap();
        $storeId = (int) $this->fixture('third_store')->getId();

        $this->assertQueuedBy(['sitemap-*'], fn () => $this->deleteStore($storeId));
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
    public function testDeletingAWebsiteQueuesEverySitemapType(): void
    {
        $this->aRebuildableSitemap();
        $websiteId = (int) $this->fixture('website')->getId();

        $this->assertQueuedBy(['sitemap-*'], fn () => $this->deleteWebsite($websiteId));
    }

    /**
     * Deleting a product takes its URL out of the sitemap.
     *
     * The entity is created here rather than by a class-level fixture: a fixture would try to
     * delete it again when it reverts.
     *
     * @return void
     */
    public function testDeletingAProductQueuesTheProductsSitemap(): void
    {
        $this->aRebuildableSitemap();
        $sku = (string) $this->create(ProductFixture::class)->getSku();

        $this->assertQueuedBy(
            ['sitemap-products'],
            fn () => Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->deleteById($sku)
        );
    }

    /**
     * Deleting a category takes its URL out of the sitemap.
     *
     * @return void
     */
    public function testDeletingACategoryQueuesTheCategoriesSitemap(): void
    {
        $this->aRebuildableSitemap();
        $categoryId = (int) $this->create(CategoryFixture::class)->getId();

        $this->assertQueuedBy(
            ['sitemap-categories'],
            fn () => Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class)
                ->deleteByIdentifier($categoryId)
        );
    }

    /**
     * Deleting a CMS page takes its URL out of the sitemap.
     *
     * @return void
     */
    public function testDeletingACmsPageQueuesThePagesSitemap(): void
    {
        $this->aRebuildableSitemap();
        $pageId = $this->createPage();

        $this->assertQueuedBy(
            ['sitemap-pages'],
            fn () => Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->deleteById($pageId)
        );
    }

    /**
     * Assert exactly which sitemap groups a change queues.
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
        foreach (self::SITEMAP_GROUPS as $group) {
            if ($flags->getFlagData('mageos_seo_feed_pending_' . $group) !== null) {
                $pending[] = $group;
            }
        }
        sort($pending);
        sort($expectedGroups);

        $this->assertSame($expectedGroups, $pending);
    }

    /**
     * Remove every pending sitemap rebuild request.
     *
     * @return void
     */
    private function clearPending(): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        foreach (self::SITEMAP_GROUPS as $group) {
            $flags->deleteFlag('mageos_seo_feed_pending_' . $group);
        }
    }

    /**
     * A sitemap for the default store view that a change would rebuild: saved with a generation
     * time, on this module's generator (the default).
     *
     * @return void
     */
    private function aRebuildableSitemap(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $sitemap       = $objectManager->create(Sitemap::class);
        $sitemap->setData([
            'sitemap_filename' => 'mageos_' . uniqid() . '.xml',
            'sitemap_path'     => '/media/sitemap/',
            'store_id'         => (int) $objectManager->get(StoreManagerInterface::class)->getStore('default')->getId(),
            'sitemap_time'     => '2026-01-01 00:00:00',
        ]);
        $objectManager->get(SitemapResource::class)->save($sitemap);
        $this->sitemap = $sitemap;

        $this->forgetRebuildableSitemaps();
    }

    /**
     * Whether any sitemap would be rebuilt is answered once per request, and every test here runs
     * in the same one: forget the answer when the sitemaps change, as the next request would.
     *
     * @return void
     */
    private function forgetRebuildableSitemaps(): void
    {
        Bootstrap::getObjectManager()->get(RebuildableSitemaps::class)->_resetState();
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
