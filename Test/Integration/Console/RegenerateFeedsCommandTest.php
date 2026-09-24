<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Console;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\Console\CommandListInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Console\Command\RegenerateFeedsCommand;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Setup\RecurringData;
use MageOS\Seo\Test\Integration\Model\Sitemap\GeneratesSitemaps;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The feed rebuild entry points outside the queue: the CLI command and the setup hook.
 *
 * @magentoAppArea global
 * @magentoDbIsolation enabled
 */
class RegenerateFeedsCommandTest extends TestCase
{
    use GeneratesSitemaps;

    /**
     * Resolve the services and the default store view.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->storage()->deleteForStore('llms*.txt', $this->storeId());
    }

    /**
     * Remove the feed files the tests wrote; the storage directory is not rolled back.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->storage()->deleteForStore('llms*.txt', $this->storeId());
        $objectManager->removeSharedInstance(FeedStorage::class);
    }

    /**
     * The command is registered with bin/magento.
     *
     * @return void
     */
    public function testCommandIsRegistered(): void
    {
        $commands = Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands();

        $this->assertArrayHasKey('mageos_seo_feeds_regenerate', $commands);
        $this->assertInstanceOf(RegenerateFeedsCommand::class, $commands['mageos_seo_feeds_regenerate']);
    }

    /**
     * Unknown groups are rejected.
     *
     * @return void
     */
    public function testTheCommandRejectsUnknownGroups(): void
    {
        $tester = new CommandTester(
            Bootstrap::getObjectManager()->create(RegenerateFeedsCommand::class)
        );

        $objectManager = Bootstrap::getObjectManager();
        $storage = $objectManager->create(FeedStorage::class);
        $storage->deleteForStore('llms*.txt', $this->storeId());
        $this->assertSame(Command::INVALID, $tester->execute(['--group' => ['robots']]));
        $this->assertNull($storage->read('llms.txt', $this->storeId()));
    }

    /**
     * Running the command builds the requested feed group immediately.
     *
     * @return void
     */
    public function testTheCommandBuildsTheRequestedGroup(): void
    {
        $this->storage()->deleteForStore('llms*.txt', $this->storeId());
        $tester = new CommandTester(
            Bootstrap::getObjectManager()->create(RegenerateFeedsCommand::class)
        );

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['llms']]), $tester->getDisplay());
        $this->assertStringStartsWith('# ', (string) $this->storage()->read('llms.txt', $this->storeId()));
        $this->assertNotNull($this->storage()->read('llms-full.txt', $this->storeId()));
    }

    /**
     * A sitemap group rebuilds that type of page in a generated sitemap, in process — with Rebuild
     * on Change off too: that setting is about changes, not about a rebuild asked for by hand.
     *
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testASitemapGroupRebuildsItsTypeWhateverRebuildOnChangeSays(): void
    {
        $this->setUpSitemaps();
        try {
            $sitemap = $this->sitemapFor($this->storeId());
            $this->generateSitemap($sitemap);
            $this->setStoreConfig(Config::XML_SITEMAP_REBUILD_ON_CHANGE, '0');
            $identifier = $this->newPage();

            $tester = new CommandTester(Bootstrap::getObjectManager()->create(RegenerateFeedsCommand::class));

            $this->assertSame(
                Command::SUCCESS,
                $tester->execute(['--group' => ['sitemap-pages']]),
                $tester->getDisplay()
            );
            $this->assertStringContainsString(
                $identifier,
                $this->pub()->readFile("media/sitemap/{$this->sitemapName}-{$this->storeId()}-pages-1.xml")
            );
        } finally {
            $this->removeGeneratedSitemaps();
        }
    }

    /**
     * Each setup run queues a rebuild of the feeds the store views can build.
     *
     * @return void
     */
    public function testSetupQueuesARebuildOfTheBuildableFeeds(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $flags         = $objectManager->get(FlagManager::class);
        foreach (['llms', 'jsonl', 'hreflang'] as $group) {
            $flags->deleteFlag('mageos_seo_feed_pending_' . $group);
        }

        $objectManager->create(RecurringData::class)->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );

        // Default configuration on a single store view: only llms.txt / llms-full.txt can be built.
        $this->assertIsNumeric($flags->getFlagData('mageos_seo_feed_pending_llms'));
        $this->assertNull($flags->getFlagData('mageos_seo_feed_pending_jsonl'));
        $this->assertNull($flags->getFlagData('mageos_seo_feed_pending_hreflang'));
    }

    private function storage()
    {
        return Bootstrap::getObjectManager()->create(FeedStorage::class);
    }

    /**
     * A new CMS page in every store view; returns its identifier.
     *
     * @return string
     */
    private function newPage(): string
    {
        $identifier = 'mageos-seo-cli-' . uniqid();

        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => 'MageOS SEO CLI',
            PageInterface::CONTENT    => '<p>CLI</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        return $identifier;
    }

    private function storeId()
    {
        return (int) Bootstrap::getObjectManager()
            ->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getId();
    }
}
