<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sitemap\Model\Sitemap;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Config\Source\SitemapGenerator;
use MageOS\Seo\Model\Feed\RegenerateConsumer;
use MageOS\Seo\Setup\RecurringData;
use PHPUnit\Framework\TestCase;

/**
 * F7: a sitemap with no file, on a store view that rebuilds on change, is written whole — after its
 * Site Map entry is saved, and after every setup run.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class FirstBuildTest extends TestCase
{
    use GeneratesSitemaps;

    private const DIRECTORY = 'media/sitemap';

    private const PENDING = 'mageos_seo_feed_pending_sitemaps-missing';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->setUpSitemaps();
        $this->flags()->deleteFlag(self::PENDING);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->flags()->deleteFlag(self::PENDING);
        $this->removeGeneratedSitemaps();
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testSavingAnEntryWithNoFileQueuesItsFirstBuildWhichWritesItWhole(): void
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $sitemap->save();

        $this->assertNotNull($this->flags()->getFlagData(self::PENDING), 'The first build was queued.');
        $this->consume();

        $this->assertArrayHasKey(
            "{$this->sitemapName}-{$this->defaultStoreId()}-products-1.xml",
            $this->index($sitemap)
        );
    }

    /**
     * @return void
     */
    public function testSavingAnEntryThatHasItsFileQueuesNothing(): void
    {
        $sitemap = $this->generated();
        $this->flags()->deleteFlag(self::PENDING);

        $sitemap->save();

        $this->assertNull($this->flags()->getFlagData(self::PENDING));
    }

    /**
     * @return void
     */
    public function testAnEntryGivenANewFileNameIsBuiltUnderIt(): void
    {
        $sitemap = $this->generated();
        $renamed = $this->sitemapName . 'b.xml';

        $sitemap->setSitemapFilename($renamed);
        $sitemap->save();

        $this->assertNotNull($this->flags()->getFlagData(self::PENDING));
        $this->consume();
        $this->assertTrue($this->pub()->isExist(self::DIRECTORY . '/' . $renamed));
    }

    /**
     * The first build writes only what is missing: a sitemap that has its file keeps it, one whose
     * file has gone is written again.
     *
     * @return void
     */
    public function testTheFirstBuildLeavesSitemapsThatHaveTheirFile(): void
    {
        $kept    = $this->generated();
        $gone    = $this->generated('2');
        $keptAt  = $this->mtime($kept);
        $gonePath = self::DIRECTORY . '/' . $gone->getSitemapFilename();
        $this->pub()->delete($gonePath);

        sleep(1);
        $this->consume();

        $this->assertSame($keptAt, $this->mtime($kept), 'A sitemap that has its file is not rewritten.');
        $this->assertTrue($this->pub()->isExist($gonePath), 'One whose file went missing is written again.');
    }

    /**
     * @return void
     */
    public function testWithRebuildOnChangeOffAnEntryWithNoFileIsNeitherQueuedNorWritten(): void
    {
        $this->setStoreConfig(Config::XML_SITEMAP_REBUILD_ON_CHANGE, '0');

        $this->assertNotBuilt($this->sitemapFor($this->defaultStoreId()));
    }

    /**
     * @return void
     */
    public function testOnMagentosGeneratorAnEntryWithNoFileIsNeitherQueuedNorWritten(): void
    {
        $this->useGenerator(SitemapGenerator::MAGENTO);

        $this->assertNotBuilt($this->sitemapFor($this->defaultStoreId()));
    }

    /**
     * @return void
     */
    public function testEverySetupRunQueuesTheFirstBuilds(): void
    {
        $this->sitemapFor($this->defaultStoreId())->save();
        $this->flags()->deleteFlag(self::PENDING);

        Bootstrap::getObjectManager()->create(RecurringData::class)->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );

        $this->assertNotNull($this->flags()->getFlagData(self::PENDING));
    }

    /**
     * Save the entry, run the first build, and find no file and nothing queued.
     *
     * @param Sitemap $sitemap
     * @return void
     */
    private function assertNotBuilt(Sitemap $sitemap): void
    {
        $sitemap->save();
        $this->assertNull($this->flags()->getFlagData(self::PENDING), 'Nothing was queued.');

        $this->consume();
        $this->assertFalse(
            $this->pub()->isExist(self::DIRECTORY . '/' . $sitemap->getSitemapFilename()),
            'Nothing was written.'
        );
    }

    /**
     * A sitemap generated for the default store view.
     *
     * @param string $suffix
     * @return Sitemap
     */
    private function generated(string $suffix = ''): Sitemap
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId(), $suffix);
        $this->generateSitemap($sitemap);

        return $sitemap;
    }

    /**
     * Run the queue consumer's first build, as the queued message would.
     *
     * @return void
     */
    private function consume(): void
    {
        Bootstrap::getObjectManager()->get(RegenerateConsumer::class)->process('sitemaps-missing');
    }

    /**
     * @param Sitemap $sitemap
     * @return int
     */
    private function mtime(Sitemap $sitemap): int
    {
        clearstatcache();

        return (int) ($this->pub()->stat(self::DIRECTORY . '/' . $sitemap->getSitemapFilename())['mtime'] ?? 0);
    }

    /**
     * @return FlagManager
     */
    private function flags(): FlagManager
    {
        return Bootstrap::getObjectManager()->get(FlagManager::class);
    }
}
