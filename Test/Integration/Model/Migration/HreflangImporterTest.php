<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Migration;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Table;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Migration\HreflangImporter;
use PHPUnit\Framework\TestCase;

/**
 * MageOS_Hreflang's settings and CMS links carried over against the real configuration table.
 *
 * The importer reads core_config_data scope by scope and writes through the config writer, so
 * these tests set values the same way and read the rows back. Every row of the paths involved is
 * snapshotted first and restored afterwards; the meta_identifier column MageOS_Hreflang would add
 * is added for the one test that needs it, unless the installation already has it.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class HreflangImporterTest extends TestCase
{
    private const PATHS = [
        'web/seo/use_hreflangs',
        'web/seo/use_sitemap_hreflangs',
        'web/seo/hreflang',
        'web/seo/hreflang_xdefault_store',
        Config::XML_HREFLANG_ENABLED,
        Config::XML_HREFLANG_SITEMAP_ENABLED,
        Config::XML_HREFLANG_CODES,
        Config::XML_HREFLANG_XDEFAULT_STORE,
    ];

    /**
     * The configuration rows of PATHS as they were before the test.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $snapshot = [];

    /**
     * Whether this test added cms_page.meta_identifier, and so must drop it.
     *
     * @var bool
     */
    private bool $addedColumn = false;

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
        $connection     = $this->connection();
        $this->snapshot = $connection->fetchAll(
            $connection->select()->from($this->table('core_config_data'))->where('path IN (?)', self::PATHS)
        );
        $this->clearPaths();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearPaths();
        foreach ($this->snapshot as $row) {
            unset($row['config_id']);
            $this->connection()->insert($this->table('core_config_data'), $row);
        }

        Bootstrap::getObjectManager()->get(ConfigRepository::class)->deleteForPages($this->createdPageIds);
        $pageRepository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // Already gone.
            }
        }

        if ($this->addedColumn) {
            $this->connection()->dropColumn($this->table('cms_page'), 'meta_identifier');
        }

        $this->snapshot       = [];
        $this->createdPageIds = [];
        $this->addedColumn    = false;
    }

    /**
     * @return void
     */
    public function testSettingsInEffectAreCarriedOverAndItsOutputSwitchedOff(): void
    {
        [$storeId, $websiteId] = $this->defaultStore();

        // MageOS_Hreflang on everywhere, this module switched off by the merchant in its favour.
        $this->set('web/seo/use_hreflangs', '1');
        $this->set('web/seo/use_sitemap_hreflangs', '1', 'websites', $websiteId);
        $this->set('web/seo/hreflang', '{"_1":{"hreflang":"en-ie"},"_2":{"hreflang":"en-uk"}}', 'stores', $storeId);
        $this->set('web/seo/hreflang_xdefault_store', (string) $storeId, 'websites', $websiteId);
        $this->set(Config::XML_HREFLANG_ENABLED, '0');

        $report = $this->importer()->import();

        $this->assertContains($storeId, $report['enabled_store_ids']);
        $this->assertSame('1', $this->stored(Config::XML_HREFLANG_ENABLED, 'stores', $storeId));

        $this->assertSame('en-IE', $this->stored(Config::XML_HREFLANG_CODES, 'stores', $storeId));
        $this->assertStringContainsString('en-uk', implode("\n", $report['notes']), 'The dropped code is reported.');

        $this->assertSame(
            (string) $storeId,
            $this->stored(Config::XML_HREFLANG_XDEFAULT_STORE, 'websites', $websiteId)
        );

        // Its sitemap was on, and this module's already is by default: nothing to write.
        $this->assertFalse($report['sitemap_enabled']);

        $this->assertSame('0', $this->stored('web/seo/use_hreflangs', 'default', 0));
        $this->assertSame('0', $this->stored('web/seo/use_sitemap_hreflangs', 'default', 0));
        $this->assertNull(
            $this->stored('web/seo/use_sitemap_hreflangs', 'websites', $websiteId),
            'Narrower values are removed, or they would switch it back on below the default.'
        );
        $this->assertContains(
            [
                'path'     => 'web/seo/use_sitemap_hreflangs',
                'scope'    => 'websites',
                'scope_id' => $websiteId,
                'value'    => '1',
            ],
            $report['switched_off'],
            'What was removed is reported, so it can be put back.'
        );
    }

    /**
     * @return void
     */
    public function testNothingHappensWhereMageOsHreflangWasOff(): void
    {
        [$storeId] = $this->defaultStore();
        $this->set('web/seo/use_hreflangs', '0');
        $this->set('web/seo/hreflang', '{"_1":{"hreflang":"en-ie"}}', 'stores', $storeId);
        $this->set(Config::XML_HREFLANG_ENABLED, '0', 'stores', $storeId);

        $report = $this->importer()->import();

        $this->assertSame([], $report['enabled_store_ids']);
        $this->assertSame([], $report['codes_store_ids'], 'Codes that were not in effect are not carried.');
        $this->assertSame([], $report['switched_off']);
        $this->assertSame('0', $this->stored(Config::XML_HREFLANG_ENABLED, 'stores', $storeId));
    }

    /**
     * @return void
     */
    public function testCodesAlreadySetInThisModuleAreKept(): void
    {
        [$storeId] = $this->defaultStore();
        $this->set('web/seo/use_hreflangs', '1');
        $this->set('web/seo/hreflang', '{"_1":{"hreflang":"en-ie"}}', 'stores', $storeId);
        $this->set(Config::XML_HREFLANG_CODES, 'en-GB', 'stores', $storeId);

        $report = $this->importer()->import();

        $this->assertSame('en-GB', $this->stored(Config::XML_HREFLANG_CODES, 'stores', $storeId));
        $this->assertStringContainsString('kept them', implode("\n", $report['notes']));
    }

    /**
     * @return void
     */
    public function testMetaIdentifiersBecomeTranslationGroups(): void
    {
        $this->ensureMetaIdentifierColumn();
        $repository = Bootstrap::getObjectManager()->get(ConfigRepository::class);

        $about    = $this->pageWithMetaIdentifier('About Us');
        $plain    = $this->pageWithMetaIdentifier('');
        $existing = $this->pageWithMetaIdentifier('legacy');
        $repository->save($existing, ['hreflang_group' => 'chosen-here']);

        $report = $this->importer()->import();

        $fresh = Bootstrap::getObjectManager()->create(ConfigRepository::class);
        $this->assertSame('about-us', $fresh->getHreflangGroup($about));
        $this->assertNull($fresh->getHreflangGroup($plain));
        $this->assertSame('chosen-here', $fresh->getHreflangGroup($existing), 'A group set here is kept.');
        $this->assertStringContainsString('"About Us" imported as "about-us"', implode("\n", $report['notes']));
    }

    /**
     * Add cms_page.meta_identifier as MageOS_Hreflang declares it, unless it is already there.
     *
     * @return void
     */
    private function ensureMetaIdentifierColumn(): void
    {
        $table = $this->table('cms_page');
        if ($this->connection()->tableColumnExists($table, 'meta_identifier')) {
            return;
        }

        $this->connection()->addColumn($table, 'meta_identifier', [
            'type'     => Table::TYPE_TEXT,
            'length'   => 255,
            'nullable' => true,
            'comment'  => 'Page hreflang association identifier',
        ]);
        $this->addedColumn = true;
    }

    /**
     * A CMS page with meta_identifier set, written straight to the column the page model does not
     * know about.
     *
     * @param string $metaIdentifier
     * @return int
     */
    private function pageWithMetaIdentifier(string $metaIdentifier): int
    {
        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-import-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO import page',
            PageInterface::CONTENT    => '<p>Import</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;

        $this->connection()->update(
            $this->table('cms_page'),
            ['meta_identifier' => $metaIdentifier],
            ['page_id = ?' => $pageId]
        );

        return $pageId;
    }

    /**
     * @param string $path
     * @param string $value
     * @param string $scope
     * @param int $scopeId
     * @return void
     */
    private function set(string $path, string $value, string $scope = 'default', int $scopeId = 0): void
    {
        Bootstrap::getObjectManager()->get(WriterInterface::class)->save($path, $value, $scope, $scopeId);
    }

    /**
     * The stored value at exactly this scope, or null when there is no row.
     *
     * @param string $path
     * @param string $scope
     * @param int $scopeId
     * @return string|null
     */
    private function stored(string $path, string $scope, int $scopeId): ?string
    {
        $connection = $this->connection();
        $value      = $connection->fetchOne(
            $connection->select()
                ->from($this->table('core_config_data'), ['value'])
                ->where('path = ?', $path)
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
        );

        return $value === false ? null : (string) $value;
    }

    /**
     * @return void
     */
    private function clearPaths(): void
    {
        $this->connection()->delete($this->table('core_config_data'), ['path IN (?)' => self::PATHS]);
    }

    /**
     * The default store view's ID and its website's.
     *
     * @return int[]
     */
    private function defaultStore(): array
    {
        $store = Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default');

        return [(int) $store->getId(), (int) $store->getWebsiteId()];
    }

    /**
     * @return HreflangImporter
     */
    private function importer(): HreflangImporter
    {
        return Bootstrap::getObjectManager()->create(HreflangImporter::class);
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
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
