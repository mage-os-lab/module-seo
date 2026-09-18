<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Feed;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Hreflang\SitemapGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Feed builds across store views, against a real install.
 *
 * Database isolation is disabled because creating a store view is not transactional.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class MultiStoreFeedBuildTest extends TestCase
{
    private const SECOND_STORE_CODE = 'seo_hreflang_de';

    /**
     * Remove the feed files the tests wrote; the storage directory is not rolled back.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->storeIds() as $storeId) {
            $this->storage()->deleteForStore('hreflang-sitemap*.xml', $storeId);
            $this->storage()->deleteForStore('llms*', $storeId);
            $this->storage()->deleteForStore('.*.tmp', $storeId);
        }
        Bootstrap::getObjectManager()->removeSharedInstance(FeedStorage::class);
    }

    /**
     * Store views sharing an alternate set get the same sitemap, built once and copied.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, ['code' => self::SECOND_STORE_CODE], 'second_store')]
    #[Config('general/locale/code', 'de_DE', ScopeInterface::SCOPE_STORE, self::SECOND_STORE_CODE)]
    public function testEveryStoreViewOfAnAlternateSetGetsTheSameSitemap(): void
    {
        $storeIds = $this->storeIds();
        $this->assertGreaterThanOrEqual(2, \count($storeIds), 'The alternate set needs two store views.');

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)
            ->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $documents = [];
        foreach ($storeIds as $storeId) {
            $documents[$storeId] = (string) $this->storage()->read(SitemapGenerator::INDEX_FILE, $storeId);
            $this->assertStringContainsString('<urlset', $documents[$storeId], "store {$storeId}");
        }

        // One alternate set, one document: every store view serves the same file.
        $this->assertCount(1, array_unique($documents));
        // And it carries both store views as alternates of each other.
        $this->assertStringContainsString('hreflang="en-US"', reset($documents));
        $this->assertStringContainsString('hreflang="de-DE"', reset($documents));
    }

    /**
     * The jsonl feed is streamed to its file, line by line.
     *
     * @return void
     */
    #[Config('mageos_seo_general/llms_txt/jsonl_enabled', 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testJsonlIsWrittenAsOneJsonObjectPerLine(): void
    {
        $storeId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')->getId();

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)
            ->regenerate(FeedRegenerator::GROUP_JSONL);

        $content = (string) $this->storage()->read('llms.jsonl', $storeId);
        $lines   = array_filter(explode("\n", $content));

        $this->assertNotSame([], $lines, 'The catalogue produced no lines.');
        foreach ($lines as $line) {
            $this->assertIsArray(json_decode($line, true), 'Every line is one JSON object: ' . $line);
        }
        $this->assertStringEndsWith("\n", $content);
    }

    /**
     * IDs of the active store views.
     *
     * @return int[]
     */
    private function storeIds(): array
    {
        $ids = [];
        foreach (Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStores() as $store) {
            if ($store->getIsActive()) {
                $ids[] = (int) $store->getId();
            }
        }

        return $ids;
    }

    private function storage()
    {
        return Bootstrap::getObjectManager()->create(FeedStorage::class);
    }
}
