<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Setup\Patch\Data;

use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Setup\Patch\Data\RemoveHreflangSitemap;
use PHPUnit\Framework\TestCase;

/**
 * The retired hreflang sitemap's files and pending flag are removed, and nothing else.
 *
 * Store directories no store view has: the patch goes by the directories in feed storage, not by
 * the store views that exist — a deleted store view's directory holds the same files.
 *
 * @magentoAppArea global
 * @magentoDbIsolation enabled
 */
class RemoveHreflangSitemapTest extends TestCase
{
    private const STORE_IDS = [999998, 999999];

    private const PENDING_FLAG = 'mageos_seo_feed_pending_hreflang';

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (self::STORE_IDS as $storeId) {
            $this->storage()->deleteStoreDirectory($storeId);
        }
    }

    /**
     * @return void
     */
    public function testTheHreflangSitemapFilesAndFlagGoAndEverythingElseStays(): void
    {
        $storage = $this->storage();
        foreach (self::STORE_IDS as $storeId) {
            $storage->write('hreflang-sitemap.xml', $storeId, '<sitemapindex/>');
            $storage->write('hreflang-sitemap-1.xml', $storeId, '<urlset/>');
        }
        [$storeId] = self::STORE_IDS;
        $storage->write('llms.txt', $storeId, '# Store');
        $storage->write('another-module.xml', $storeId, '<other/>');
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        $flags->saveFlag(self::PENDING_FLAG, time());
        $flags->saveFlag('mageos_seo_feed_pending_llms', time());

        Bootstrap::getObjectManager()->create(RemoveHreflangSitemap::class)->apply();

        foreach (self::STORE_IDS as $id) {
            $this->assertNull($storage->read('hreflang-sitemap.xml', $id), "The index is gone from store $id.");
            $this->assertNull($storage->read('hreflang-sitemap-1.xml', $id), "The chunk is gone from store $id.");
        }
        $this->assertSame('# Store', $storage->read('llms.txt', $storeId), 'Another feed stays.');
        $this->assertSame('<other/>', $storage->read('another-module.xml', $storeId), 'Any other file stays.');
        $this->assertNull($flags->getFlagData(self::PENDING_FLAG), 'Nothing would ever clear the flag.');
        $this->assertNotNull($flags->getFlagData('mageos_seo_feed_pending_llms'), 'Other groups\' flags stay.');
    }

    /**
     * @return FeedStorage
     */
    private function storage(): FeedStorage
    {
        return Bootstrap::getObjectManager()->create(FeedStorage::class);
    }
}
