<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Sitemap\Rebuilder;
use PHPUnit\Framework\TestCase;

/**
 * A Site Map entry's files go with the entry — deleted itself, or with its store view — and only
 * once the deletion commits; files another entry still uses stay.
 *
 * Core's Delete action removes the index file before it deletes the entry; these delete through
 * the resource model, as a data patch would, so the index is this module's to remove too.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class FileRemovalTest extends TestCase
{
    use GeneratesSitemaps;

    private const DIRECTORY = 'media/sitemap';

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
        $this->removeGeneratedSitemaps();
    }

    /**
     * Both sets, the names from before the sets and core's go, and the index; another sitemap's
     * file stays.
     *
     * @return void
     */
    public function testDeletingAnEntryRemovesItsFiles(): void
    {
        $sitemap = $this->generatedSitemap();
        Bootstrap::getObjectManager()->get(Rebuilder::class)->rebuildOnDemand('pages');
        $store    = $this->defaultStoreId();
        $legacy   = "{$this->sitemapName}-{$store}-products-7.xml";
        $core     = "{$this->sitemapName}-{$store}-3.xml";
        $stranger = "{$this->sitemapName}x-{$store}-pages-a-1.xml";
        foreach ([$legacy, $core, $stranger] as $name) {
            $this->pub()->writeFile(self::DIRECTORY . '/' . $name, '');
        }
        $this->assertContains("{$this->sitemapName}-{$store}-pages-a-1.xml", $this->files(), 'Both sets are there.');
        $this->assertContains("{$this->sitemapName}-{$store}-pages-b-1.xml", $this->files(), 'Both sets are there.');

        $this->delete($sitemap);

        $this->assertSame([$stranger], $this->files());
    }

    /**
     * Another entry for the same file and store view uses every file: they all stay.
     *
     * @return void
     */
    public function testFilesAnotherEntryUsesStay(): void
    {
        $sitemap = $this->generatedSitemap();
        $files   = $this->files();
        $this->sitemapFor($this->defaultStoreId())->save();

        $this->delete($sitemap);

        $this->assertSame($files, $this->files());
    }

    /**
     * Another entry for the same file but another store view uses the index only: it stays, the
     * deleted entry's other files go.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    public function testAnIndexAnotherStoreViewsEntryUsesStays(): void
    {
        $sitemap = $this->generatedSitemap();
        $this->sitemapFor((int) DataFixtureStorageManager::getStorage()->get('german')->getId())->save();

        $this->delete($sitemap);

        $this->assertSame([(string) $sitemap->getSitemapFilename()], $this->files());
    }

    /**
     * A deleted store view takes its entries through the foreign key, which runs no model code;
     * their files go all the same.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    public function testDeletingAStoreViewRemovesItsEntriesFiles(): void
    {
        $germanId = (int) DataFixtureStorageManager::getStorage()->get('german')->getId();
        $this->generateFor($germanId);
        $this->assertNotSame([], $this->files());

        $objectManager = Bootstrap::getObjectManager();
        $store         = $objectManager->get(StoreFactory::class)->create();
        $objectManager->get(StoreResource::class)->load($store, $germanId);
        $objectManager->get(StoreResource::class)->delete($store);
        $objectManager->get(StoreManagerInterface::class)->reinitStores();

        $this->assertSame([], $this->files());
    }

    /**
     * A deletion that rolls back keeps the entry, and so its files.
     *
     * Rolled back through a resource model, as Magento code does: that is what drops the commit
     * callbacks registered inside the transaction.
     *
     * @return void
     */
    public function testADeletionThatRollsBackKeepsTheFiles(): void
    {
        $sitemap  = $this->generatedSitemap();
        $files    = $this->files();
        $resource = Bootstrap::getObjectManager()->get(SitemapResource::class);

        $resource->beginTransaction();
        try {
            $resource->delete($sitemap);
        } finally {
            $resource->rollBack();
        }

        $this->assertSame($files, $this->files());
    }

    /**
     * A sitemap for the default store view, generated and saved.
     *
     * @return Sitemap
     */
    private function generatedSitemap(): Sitemap
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $this->generateSitemap($sitemap);
        $sitemap->save();
        $this->assertNotSame([], $this->files(), 'The comparison needs files.');

        return $sitemap;
    }

    /**
     * Delete an entry through its resource model.
     *
     * @param Sitemap $sitemap
     * @return void
     */
    private function delete(Sitemap $sitemap): void
    {
        Bootstrap::getObjectManager()->get(SitemapResource::class)->delete($sitemap);
    }

    /**
     * This test's files in the sitemap directory — those named after its sitemaps — sorted.
     *
     * @return string[]
     */
    private function files(): array
    {
        $names = [];
        foreach ($this->pub()->read(self::DIRECTORY) as $path) {
            $name = basename($path);
            if (str_starts_with($name, $this->sitemapName)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
