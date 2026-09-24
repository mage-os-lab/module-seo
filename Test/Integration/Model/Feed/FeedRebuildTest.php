<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Feed;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Feed\RegenerateConsumer;
use MageOS\Seo\Model\Feed\RegenerationRequester;
use PHPUnit\Framework\TestCase;

/**
 * Invalidation and rebuild of the pre-generated feeds against a real install.
 *
 * An invalidation must leave the served files alone (a save used to delete them and take
 * the feed offline until the consumer ran); only the consumer replaces them.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class FeedRebuildTest extends TestCase
{
    private const FLAG_LLMS = 'mageos_seo_feed_pending_llms';

    /**
     * Remove the feed files the tests wrote; the storage directory is not rolled back.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->storage()->deleteForStore('llms*.txt', $this->storeId());
        $this->storage()->deleteForStore('.*.tmp', $this->storeId());
    }

    /**
     * Saving a category queues a rebuild and leaves the served file in place.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testSavingACategoryKeepsTheServedFeedAndQueuesARebuild(): void
    {
        $this->storage()->write('llms.txt', $this->storeId(), 'served before the save');
        // The fixture's own save already queued a rebuild; start from a clean slate.
        $this->flags()->deleteFlag(self::FLAG_LLMS);

        $repository = Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class);
        $category   = $repository->get((int) DataFixtureStorageManager::getStorage()->get('category')->getId());
        $category->setName('Renamed to invalidate the feeds');
        $repository->save($category);

        $this->assertSame('served before the save', $this->storage()->read('llms.txt', $this->storeId()));
        $this->assertIsNumeric($this->flags()->getFlagData(self::FLAG_LLMS), 'No llms rebuild was queued.');
    }

    /**
     * Rewriting a feed replaces its content and leaves no temporary files behind.
     *
     * @return void
     */
    public function testWritingReplacesTheFileWithoutLeavingTemporaryFiles(): void
    {
        $this->storage()->write('llms.txt', $this->storeId(), 'first');
        $this->storage()->write('llms.txt', $this->storeId(), 'second');

        $this->assertSame('second', $this->storage()->read('llms.txt', $this->storeId()));

        $var      = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryRead(DirectoryList::VAR_DIR);
        $storeDir = 'mageos_seo/store_' . $this->storeId();
        $this->assertSame(
            [],
            array_values(array_filter(
                $var->read($storeDir),
                static fn (string $path): bool => str_ends_with($path, '.tmp')
            )),
            'No temporary file is left behind.'
        );
        $this->assertSame('640', $this->mode($var->getAbsolutePath($storeDir . '/llms.txt')));
        $this->assertSame('750', $this->mode($var->getAbsolutePath($storeDir)));
        $this->assertSame('750', $this->mode($var->getAbsolutePath('mageos_seo')));
    }

    /**
     * Octal permission bits of a path.
     *
     * @param string $path
     * @return string
     */
    private function mode(string $path): string
    {
        clearstatcache(true, $path);

        return decoct(fileperms($path) & 0o777);
    }

    /**
     * The consumer clears the pending request and replaces the outdated file.
     *
     * @return void
     */
    public function testTheConsumerRebuildsTheGroupAndClearsThePendingFlag(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->flags()->deleteFlag(self::FLAG_LLMS);
        $objectManager->get(RegenerationRequester::class)->request(FeedRegenerator::GROUP_LLMS);
        $this->assertIsNumeric($this->flags()->getFlagData(self::FLAG_LLMS));
        $this->storage()->write('llms.txt', $this->storeId(), 'outdated');

        $objectManager->get(RegenerateConsumer::class)->process(FeedRegenerator::GROUP_LLMS);

        $this->assertStringStartsWith('# ', (string) $this->storage()->read('llms.txt', $this->storeId()));
        $this->assertNotNull($this->storage()->read('llms-full.txt', $this->storeId()));
        $this->assertNull($this->flags()->getFlagData(self::FLAG_LLMS));
    }

    private function storage()
    {
        return Bootstrap::getObjectManager()->create(FeedStorage::class);
    }

    private function flags()
    {
        return Bootstrap::getObjectManager()->create(FlagManager::class);
    }

    private function storeId()
    {
        return (int) Bootstrap::getObjectManager()
            ->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getId();
    }
}
