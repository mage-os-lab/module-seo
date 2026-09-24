<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Feed;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
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
    /**
     * Remove the feed files the tests wrote; the storage directory is not rolled back.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->storeIds() as $storeId) {
            $this->storage()->deleteForStore('llms*', $storeId);
            $this->storage()->deleteForStore('.*.tmp', $storeId);
        }
        Bootstrap::getObjectManager()->removeSharedInstance(FeedStorage::class);
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
