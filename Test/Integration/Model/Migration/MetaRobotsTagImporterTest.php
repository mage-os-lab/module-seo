<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Migration;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Setup\CategorySetup;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\Migration\FlagsToDirective;
use MageOS\Seo\Model\Migration\MetaRobotsTagImporter;
use MageOS\Seo\Model\Product\OverrideRepository;
use PHPUnit\Framework\TestCase;

/**
 * MageOS_MetaRobotsTag's flags carried over against the real catalogue and CMS tables: issue #26
 * (a value already set here is never replaced) and review finding H6 (flags are per store view, and
 * each store view resolves them against its own default robots).
 *
 * That module's three attributes and cms_page columns are created as it creates them, unless the
 * installation already has it; whatever this test created is removed afterwards. The products,
 * categories and store view are data fixtures, reverted with the rows this module keeps for them;
 * the CMS pages and their rows are removed here.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class MetaRobotsTagImporterTest extends TestCase
{
    private const PATH_DEFAULT_ROBOTS = 'design/search_engine_robots/default_robots';

    /**
     * The attributes this test created, as [entity type, code] pairs.
     *
     * @var array<int, array{0: string, 1: string}>|null
     */
    private ?array $createdAttributes = [];

    /**
     * The cms_page columns this test created.
     *
     * @var string[]|null
     */
    private ?array $createdColumns = [];

    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->ensureFlags();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->get(CmsPageConfigRepository::class)->deleteForPages($this->createdPageIds);
        $pageRepository = $objectManager->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // Already gone.
            }
        }

        $setup = $objectManager->create(CategorySetup::class);
        foreach ($this->createdAttributes as [$entityType, $code]) {
            $setup->removeAttribute($entityType, $code);
        }
        foreach ($this->createdColumns as $column) {
            $this->connection()->dropColumn($this->table('cms_page'), $column);
        }
        $objectManager->get(EavConfig::class)->clear();

        $this->createdAttributes = [];
        $this->createdColumns    = [];
        $this->createdPageIds    = [];
    }

    /**
     * H6: flags are read at each store view and resolved against that store view's default.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(ProductFixture::class, as: 'everywhere')]
    #[DataFixture(ProductFixture::class, as: 'one_store')]
    #[DataFixture(ProductFixture::class, as: 'unflagged')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testEachStoreViewGetsTheDirectiveItWasServed(): void
    {
        [$firstStore, $secondStore] = $this->storesWithDefaults('INDEX,FOLLOW', 'NOINDEX,NOFOLLOW');

        // Resolves the same at both store views: one global row.
        $everywhere = $this->id('everywhere');
        $this->flag(Product::ENTITY, $everywhere, 'no_index', 0);
        $this->flag(Product::ENTITY, $everywhere, 'no_follow', 0);

        // Flagged for one store view only: the other keeps following its store setting.
        $oneStore = $this->id('one_store');
        $this->flag(Product::ENTITY, $oneStore, 'no_follow', $firstStore);

        // Flagged for every store view, but the store views' defaults differ.
        $category = $this->id('category');
        $this->flag(Category::ENTITY, $category, 'no_archive', 0);
        $page = $this->page();
        $this->flagPage($page, 'no_index');

        $report = $this->importer()->import();

        $this->assertSame([0 => 'NOINDEX,NOFOLLOW'], $this->productRows($everywhere));
        $this->assertSame([$firstStore => 'INDEX,NOFOLLOW'], $this->productRows($oneStore));
        $this->assertSame([], $this->productRows($this->id('unflagged')), 'An entity with no flag is not touched.');
        $this->assertSame(
            [$firstStore => 'INDEX,FOLLOW,noarchive', $secondStore => 'NOINDEX,NOFOLLOW,noarchive'],
            $this->categoryRows($category)
        );
        $this->assertSame(
            [$firstStore => 'NOINDEX,FOLLOW', $secondStore => 'NOINDEX,NOFOLLOW'],
            $this->pageRows($page),
            'A CMS page\'s flags have no store values, but each store view\'s default still applies.'
        );
        $this->assertSame(
            [\sprintf(
                'CMS page %d: the directives differ by store view, so they are set per store view, which its'
                . ' admin form does not show: store view %d "NOINDEX,FOLLOW", store view %d "NOINDEX,NOFOLLOW".',
                $page,
                $firstStore,
                $secondStore
            )],
            $report['notes'],
            'Only the CMS page\'s store view rows are reported: the product and category forms show them.'
        );
    }

    /**
     * Issue #26: a value already set here is kept, whether it is the store view's own row or the
     * global row the store view inherits, and each one kept is reported with both directives.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testAValueAlreadySetHereIsKeptAndReported(): void
    {
        [$firstStore, $secondStore] = $this->storesWithDefaults('INDEX,FOLLOW', 'NOINDEX,NOFOLLOW');
        $objectManager = Bootstrap::getObjectManager();

        // A global row, where one global row would be imported.
        $product = $this->id('product');
        $this->flag(Product::ENTITY, $product, 'no_index', 0);
        $this->flag(Product::ENTITY, $product, 'no_follow', 0);
        $objectManager->get(OverrideRepository::class)->save($product, 0, ['robots_meta' => 'INDEX,NOFOLLOW']);

        // A store view's own row, where a row per store view would be imported.
        $category = $this->id('category');
        $this->flag(Category::ENTITY, $category, 'no_archive', 0);
        $objectManager->get(CategoryConfigRepository::class)
            ->save($category, ['robots_meta' => 'NOINDEX,FOLLOW'], $secondStore);

        // A global row both store views inherit, where a row per store view would be imported.
        $page = $this->page();
        $this->flagPage($page, 'no_index');
        $objectManager->get(CmsPageConfigRepository::class)->save($page, ['robots_meta' => 'INDEX,FOLLOW']);

        $report = $this->importer()->import();

        $this->assertSame([0 => 'INDEX,NOFOLLOW'], $this->productRows($product));
        $this->assertSame(
            [$firstStore => 'INDEX,FOLLOW,noarchive', $secondStore => 'NOINDEX,FOLLOW'],
            $this->categoryRows($category),
            'The store view with nothing set here gets the imported directive.'
        );
        $this->assertSame([0 => 'INDEX,FOLLOW'], $this->pageRows($page));
        $this->assertSame(['product' => 0, 'category' => 1, 'cms_page' => 0], $report['written']);

        $notes = implode("\n", $report['notes']);
        $this->assertStringContainsString(
            \sprintf('Product %d, all store views: kept "INDEX,NOFOLLOW"', $product),
            $notes
        );
        $this->assertStringContainsString('"NOINDEX,NOFOLLOW"', $notes);
        $this->assertStringContainsString(
            \sprintf('Category %d, store view %d: kept "NOINDEX,FOLLOW"', $category, $secondStore),
            $notes
        );
        $this->assertStringContainsString('"NOINDEX,NOFOLLOW,noarchive"', $notes);
        foreach ([$firstStore => 'NOINDEX,FOLLOW', $secondStore => 'NOINDEX,NOFOLLOW'] as $storeId => $imported) {
            $this->assertStringContainsString(
                \sprintf('CMS page %d, store view %d: kept "INDEX,FOLLOW"', $page, $storeId),
                $notes
            );
            $this->assertStringContainsString(\sprintf('"%s"', $imported), $notes);
        }
    }

    /**
     * A dry run writes nothing; once imported, a second run finds nothing left to write.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testADryRunWritesNothingAndASecondRunChangesNothing(): void
    {
        $this->storesWithDefaults('INDEX,FOLLOW');
        $product = $this->id('product');
        $this->flag(Product::ENTITY, $product, 'no_index', 0);

        $dryRun = $this->importer()->import(true);

        $this->assertGreaterThanOrEqual(1, $dryRun['written']['product'], 'The dry run counts what it would write.');
        $this->assertSame([], $this->productRows($product));

        $this->importer()->import();
        $this->assertSame([0 => 'NOINDEX,FOLLOW'], $this->productRows($product));

        $again = $this->importer()->import();

        $this->assertSame(['product' => 0, 'category' => 0, 'cms_page' => 0], $again['written']);
        $this->assertSame([], $again['notes'], 'Its own earlier import is not a conflict.');
        $this->assertSame([0 => 'NOINDEX,FOLLOW'], $this->productRows($product));
    }

    /**
     * Set the default robots of the default store view and, when given, of the fixture store view.
     *
     * @param string $first
     * @param string|null $second
     * @return int[] The two store views' IDs, the second only when given
     */
    private function storesWithDefaults(string $first, ?string $second = null): array
    {
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        $config->setValue(self::PATH_DEFAULT_ROBOTS, $first, ScopeInterface::SCOPE_STORE, 'default');
        $default  = Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default');
        $storeIds = [(int) $default->getId()];

        if ($second !== null) {
            $store = DataFixtureStorageManager::getStorage()->get('second_store');
            $config->setValue(self::PATH_DEFAULT_ROBOTS, $second, ScopeInterface::SCOPE_STORE, $store->getCode());
            $storeIds[] = (int) $store->getId();
        }

        return $storeIds;
    }

    /**
     * Set one of MageOS_MetaRobotsTag's flags on a product or category, for a store view (0: all).
     *
     * @param string $entityType
     * @param int $entityId
     * @param string $flag
     * @param int $storeId
     * @return void
     */
    private function flag(string $entityType, int $entityId, string $flag, int $storeId): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource      = $entityType === Product::ENTITY
            ? $objectManager->get(ProductResource::class)
            : $objectManager->get(CategoryResource::class);
        $entity        = $objectManager->create($entityType === Product::ENTITY ? Product::class : Category::class);
        $entity->setData(['entity_id' => $entityId, 'store_id' => $storeId, $flag => 1]);

        $resource->saveAttribute($entity, $flag);
    }

    /**
     * Set one of MageOS_MetaRobotsTag's columns on a CMS page, which the page model does not know.
     *
     * @param int $pageId
     * @param string $flag
     * @return void
     */
    private function flagPage(int $pageId, string $flag): void
    {
        $this->connection()->update($this->table('cms_page'), [$flag => 1], ['page_id = ?' => $pageId]);
    }

    /**
     * A CMS page on every store view.
     *
     * @return int
     */
    private function page(): int
    {
        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-robots-import-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO robots import page',
            PageInterface::CONTENT    => '<p>Import</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;

        return $pageId;
    }

    /**
     * Create MageOS_MetaRobotsTag's attributes and columns as it does, where they are missing.
     *
     * @return void
     */
    private function ensureFlags(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $eavConfig     = $objectManager->get(EavConfig::class);
        $setup         = $objectManager->create(CategorySetup::class);

        foreach ([Product::ENTITY, Category::ENTITY] as $entityType) {
            foreach (FlagsToDirective::FLAGS as $flag) {
                if ($eavConfig->getAttribute($entityType, $flag)->getAttributeId()) {
                    continue;
                }
                $setup->addAttribute($entityType, $flag, [
                    'label'        => $flag,
                    'type'         => 'int',
                    'input'        => 'boolean',
                    'source'       => Boolean::class,
                    'global'       => ScopedAttributeInterface::SCOPE_STORE,
                    'visible'      => true,
                    'required'     => false,
                    'user_defined' => false,
                    'default'      => 0,
                ]);
                $this->createdAttributes[] = [$entityType, $flag];
            }
        }
        $eavConfig->clear();

        $table = $this->table('cms_page');
        foreach (FlagsToDirective::FLAGS as $flag) {
            if ($this->connection()->tableColumnExists($table, $flag)) {
                continue;
            }
            $this->connection()->addColumn($table, $flag, [
                'type'     => Table::TYPE_SMALLINT,
                'nullable' => false,
                'default'  => 0,
                'comment'  => $flag,
            ]);
            $this->createdColumns[] = $flag;
        }
    }

    /**
     * @param int $productId
     * @return array<int, string> The robots this module stores for the product, by store view
     */
    private function productRows(int $productId): array
    {
        return $this->robotsRows('mageos_seo_product_override', 'product_id', $productId);
    }

    /**
     * @param int $categoryId
     * @return array<int, string> The robots this module stores for the category, by store view
     */
    private function categoryRows(int $categoryId): array
    {
        return $this->robotsRows('mageos_seo_category_config', 'category_id', $categoryId);
    }

    /**
     * @param int $pageId
     * @return array<int, string> The robots this module stores for the page, by store view
     */
    private function pageRows(int $pageId): array
    {
        return $this->robotsRows('mageos_seo_cms_page_config', 'page_id', $pageId);
    }

    /**
     * The non-empty robots of an entity's rows, by store view, in store view order.
     *
     * @param string $table
     * @param string $column
     * @param int $entityId
     * @return array<int, string>
     */
    private function robotsRows(string $table, string $column, int $entityId): array
    {
        $connection = $this->connection();
        $rows       = $connection->fetchPairs(
            $connection->select()
                ->from($this->table($table), ['store_id', 'robots_meta'])
                ->where($column . ' = ?', $entityId)
                ->where('robots_meta IS NOT NULL')
                ->where("robots_meta != ''")
                ->order('store_id')
        );

        return array_map('strval', $rows);
    }

    /**
     * The ID of an entity created by a data fixture.
     *
     * @param string $name
     * @return int
     */
    private function id(string $name): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($name)->getId();
    }

    /**
     * @return MetaRobotsTagImporter
     */
    private function importer(): MetaRobotsTagImporter
    {
        return Bootstrap::getObjectManager()->create(MetaRobotsTagImporter::class);
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
    }

    /**
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return Bootstrap::getObjectManager()->get(ResourceConnection::class)->getTableName($name);
    }
}
