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
use Magento\Config\Model\Config as ConfigModel;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Sitemap\RebuildRequesterInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsConfigRepository;
use MageOS\Seo\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * F5b: which real changes queue which sitemap rebuilds.
 *
 * A change counts when it can change what a sitemap lists; the sitemap must be one a rebuild would
 * touch — generated before, on this module's generator — or nothing is queued at all.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SitemapInvalidationRulesTest extends TestCase
{
    use GeneratesSitemaps;

    private const GROUPS = ['sitemap-pages', 'sitemap-categories', 'sitemap-products', 'sitemap-*'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->setUpSitemaps();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearPending();
        $this->removeGeneratedSitemaps();
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testProductChangesQueueTheProductsOnlyWhenWhatIsListedChanges(): void
    {
        $this->aGeneratedSitemap();
        $sku = (string) DataFixtureStorageManager::getStorage()->get('product')->getSku();

        $this->assertQueuedBy([], fn () => $this->saveProduct($sku, ['name' => 'Renamed']));
        $this->assertQueuedBy(
            ['sitemap-products'],
            fn () => $this->saveProduct($sku, ['url_key' => 'renamed-' . uniqid()])
        );
        $this->assertQueuedBy(['sitemap-products'], fn () => $this->saveProduct($sku, ['status' => 2]));
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testMassUpdatesQueueTheProductsOnlyForWhatIsListed(): void
    {
        $this->aGeneratedSitemap();
        $productId = (int) DataFixtureStorageManager::getStorage()->get('product')->getId();
        $action    = Bootstrap::getObjectManager()->create(ProductAction::class);

        $this->assertQueuedBy([], fn () => $action->updateAttributes([$productId], ['meta_title' => 'T'], 0));
        $this->assertQueuedBy(
            ['sitemap-products'],
            fn () => $action->updateAttributes([$productId], ['visibility' => 1], 0)
        );
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testCategoryAndPageChangesQueueTheirOwnType(): void
    {
        $this->aGeneratedSitemap();
        $categoryId = (int) DataFixtureStorageManager::getStorage()->get('category')->getId();
        $pageId     = $this->newPage();

        $this->assertQueuedBy([], fn () => $this->saveCategory($categoryId, ['description' => 'New copy']));
        $this->assertQueuedBy(
            ['sitemap-categories'],
            fn () => $this->saveCategory($categoryId, ['url_key' => 'renamed-' . uniqid()])
        );
        $this->assertQueuedBy([], fn () => $this->savePage($pageId, ['title' => 'Retitled']));
        $this->assertQueuedBy(
            ['sitemap-pages'],
            fn () => $this->savePage($pageId, ['identifier' => 'moved-' . uniqid()])
        );
    }

    /**
     * The robots directive decides whether a page is listed, a translation group its alternates.
     *
     * Each row is first created without either — which changes nothing a sitemap shows — and then
     * saved again, loaded through a collection as the repositories load it: that second save is
     * where "changed" is decided, from the row's original data.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testThisModulesSettingsQueueTheirTypeWhenTheDirectiveOrGroupChanges(): void
    {
        $this->aGeneratedSitemap();
        $storage    = DataFixtureStorageManager::getStorage();
        $productId  = (int) $storage->get('product')->getId();
        $categoryId = (int) $storage->get('category')->getId();
        $pageId     = $this->newPage();
        $om         = Bootstrap::getObjectManager();

        $products = $om->get(ProductOverrideRepository::class);
        $this->assertQueuedBy([], fn () => $products->save($productId, 0, ['override_fields' => ['brand' => 'A']]));
        $this->assertQueuedBy(
            ['sitemap-products'],
            fn () => $products->save($productId, 0, ['robots_meta' => 'NOINDEX,FOLLOW'])
        );
        $this->assertQueuedBy([], fn () => $products->save($productId, 0, ['override_fields' => ['brand' => 'B']]));

        $categories = $om->get(CategoryConfigRepository::class);
        $this->assertQueuedBy([], fn () => $categories->save($categoryId, ['override_fields' => ['a' => 'b']]));
        $this->assertQueuedBy(
            ['sitemap-categories'],
            fn () => $categories->save($categoryId, ['robots_meta' => 'NOINDEX,FOLLOW'])
        );

        $pages = $om->get(CmsConfigRepository::class);
        $this->assertQueuedBy([], fn () => $pages->save($pageId, ['robots_meta' => '']));
        $this->assertQueuedBy(['sitemap-pages'], fn () => $pages->save($pageId, ['hreflang_group' => 'about']));
        $this->assertQueuedBy([], fn () => $pages->save($pageId, ['hreflang_group' => 'about']));
    }

    /**
     * Configuration saved as the admin's pages and `bin/magento config:set` save it: through core's
     * config model, which saves every field it is given, changed or not.
     *
     * @return void
     */
    public function testConfigurationQueuesEveryTypeOnlyForSitemapPathsThatChange(): void
    {
        $this->aGeneratedSitemap();
        $current = (string) Bootstrap::getObjectManager()->get(ScopeConfigInterface::class)
            ->getValue('sitemap/limit/max_lines');

        $this->assertQueuedBy([], fn () => $this->saveConfig('sitemap/limit/max_lines', $current));
        $this->assertQueuedBy([], fn () => $this->saveConfig('contact/email/recipient_email', 'x@example.com'));
        $this->assertQueuedBy(
            ['sitemap-*'],
            fn () => $this->saveConfig('sitemap/limit/max_lines', (string) ((int) $current - 1))
        );
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testNothingIsQueuedWithoutASitemapToRebuild(): void
    {
        // Configured, but never generated: rebuilding never makes a first sitemap.
        $this->sitemapFor($this->defaultStoreId())->save();
        $sku = (string) DataFixtureStorageManager::getStorage()->get('product')->getSku();

        $this->assertQueuedBy([], fn () => $this->saveProduct($sku, ['url_key' => 'renamed-' . uniqid()]));
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testNothingIsQueuedWhenTheStoreViewHasRebuildOnChangeOff(): void
    {
        $this->aGeneratedSitemap();
        $this->setStoreConfig(Config::XML_SITEMAP_REBUILD_ON_CHANGE, '0');
        $sku = (string) DataFixtureStorageManager::getStorage()->get('product')->getSku();

        $this->assertQueuedBy([], fn () => $this->saveProduct($sku, ['url_key' => 'renamed-' . uniqid()]));
    }

    /**
     * Another module asks through the public interface: its type is queued like this module's own,
     * and a type no provider has is refused before anything is queued.
     *
     * @return void
     */
    public function testAnotherModuleRequestsATypeThroughThePublicInterface(): void
    {
        $this->aGeneratedSitemap();
        $requester = Bootstrap::getObjectManager()->get(RebuildRequesterInterface::class);

        $this->assertQueuedBy(['sitemap-products'], fn () => $requester->request('products'));
        $this->assertQueuedBy(['sitemap-*'], fn () => $requester->request(RebuildRequesterInterface::ALL_TYPES));

        try {
            $requester->request('no-such-type');
            $this->fail('A type no provider has was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertNull(
                Bootstrap::getObjectManager()->get(FlagManager::class)
                    ->getFlagData('mageos_seo_feed_pending_sitemap-no-such-type')
            );
        }
    }

    /**
     * A sitemap a rebuild would touch: saved with a generation time, on this module's generator.
     *
     * @return void
     */
    private function aGeneratedSitemap(): void
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $sitemap->setSitemapTime('2026-01-01 00:00:00');
        $sitemap->save();
    }

    /**
     * Assert which sitemap groups the change queues, and no others.
     *
     * @param string[] $expected
     * @param callable $change
     * @return void
     */
    private function assertQueuedBy(array $expected, callable $change): void
    {
        $this->clearPending();
        $change();

        $flags  = Bootstrap::getObjectManager()->get(FlagManager::class);
        $queued = array_values(array_filter(
            self::GROUPS,
            static fn (string $group): bool => $flags->getFlagData('mageos_seo_feed_pending_' . $group) !== null
        ));

        $this->assertSame($expected, $queued);
    }

    /**
     * @return void
     */
    private function clearPending(): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        foreach (self::GROUPS as $group) {
            $flags->deleteFlag('mageos_seo_feed_pending_' . $group);
        }
    }

    /**
     * @param string $sku
     * @param array<string,mixed> $changes
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
     * @param int $categoryId
     * @param array<string,mixed> $changes
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
     * @param int $pageId
     * @param array<string,mixed> $changes
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
     * Save one value at the default scope as `bin/magento config:set` does.
     *
     * @param string $path
     * @param string $value
     * @return void
     */
    private function saveConfig(string $path, string $value): void
    {
        $config = Bootstrap::getObjectManager()->create(
            ConfigModel::class,
            ['data' => ['scope' => 'default', 'scope_code' => null]]
        );
        $config->setDataByPath($path, $value);
        $config->save();
    }

    /**
     * A new CMS page in every store view; returns its ID.
     *
     * @return int
     */
    private function newPage(): int
    {
        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-invalidation-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO invalidation',
            PageInterface::CONTENT    => '<p>Invalidation</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        return (int) $page->getId();
    }
}
