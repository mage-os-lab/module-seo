<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Console;

use Magento\Framework\Console\CommandListInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Console\Command\RegenerateFeedsCommand;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Setup\RecurringData;
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
    /**
     * @var FeedStorage
     */
    private FeedStorage $storage;

    /**
     * @var int
     */
    private int $storeId;

    /**
     * Resolve the services and the default store view.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->storage = $objectManager->create(FeedStorage::class);
        $this->storeId = (int) $objectManager->get(StoreManagerInterface::class)->getStore('default')->getId();
        $this->storage->deleteForStore('llms*.txt', $this->storeId);
    }

    /**
     * Remove the feed files the tests wrote; the storage directory is not rolled back.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->storage->deleteForStore('llms*.txt', $this->storeId);
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
        $storage->deleteForStore('llms*.txt', $this->storeId);
        $this->assertSame(Command::INVALID, $tester->execute(['--group' => ['robots']]));
        $this->assertNull($storage->read('llms.txt', $this->storeId));
    }

    /**
     * Running the command builds the requested feed group immediately.
     *
     * @return void
     */
    public function testTheCommandBuildsTheRequestedGroup(): void
    {
        $this->storage->deleteForStore('llms*.txt', $this->storeId);
        $tester = new CommandTester(
            Bootstrap::getObjectManager()->create(RegenerateFeedsCommand::class)
        );

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['llms']]), $tester->getDisplay());
        $this->assertStringStartsWith('# ', (string) $this->storage->read('llms.txt', $this->storeId));
        $this->assertNotNull($this->storage->read('llms-full.txt', $this->storeId));
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
}
