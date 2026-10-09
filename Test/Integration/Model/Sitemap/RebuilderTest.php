<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sitemap\Model\Sitemap;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Config\Source\SitemapGenerator;
use MageOS\Seo\Model\Product\OverrideRepository;
use MageOS\Seo\Model\Rebuild\RegenerateConsumer;
use MageOS\Seo\Model\Sitemap\GenerationLock;
use MageOS\Seo\Model\Sitemap\Generator;
use MageOS\Seo\Model\Sitemap\Rebuilder;
use MageOS\Seo\Test\Integration\Model\Sitemap\Fixture\HeldSitemapLocks;
use MageOS\Seo\Test\Integration\Rebuild\CommitsDeferredRequests;
use PHPUnit\Framework\TestCase;

/**
 * F5a: one type of a sitemap is rewritten after a change, the others kept as they were.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RebuilderTest extends TestCase
{
    use GeneratesSitemaps;
    use CommitsDeferredRequests;

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
     * A page added after the sitemap was generated is in it after the pages are rebuilt — into
     * their other set, the published one left whole until the switch; the products file is the
     * same file — same bytes, same time — and the index keeps its date.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testRebuildingATypeRewritesOnlyItsFiles(): void
    {
        $sitemap = $this->generated();
        $before  = $this->index($sitemap);
        $store   = $this->defaultStoreId();
        $pages   = "{$this->sitemapName}-{$store}-pages-a-1.xml";
        $product = "{$this->sitemapName}-{$store}-products-a-1.xml";
        $this->assertArrayHasKey($pages, $before, 'A first generation writes the first set.');
        $this->assertArrayHasKey($product, $before, 'The comparison needs a products file.');
        $pagesBytes   = $this->pub()->readFile(self::DIRECTORY . '/' . $pages);
        $productBytes = $this->pub()->readFile(self::DIRECTORY . '/' . $product);
        $productTime  = $this->mtime($product);

        // A second, so a date written now differs from one kept.
        sleep(1);
        $identifier = $this->newPage();

        $this->rebuilder()->rebuild('pages');

        $after      = $this->index($sitemap);
        $newPages   = "{$this->sitemapName}-{$store}-pages-b-1.xml";
        $categories = "{$this->sitemapName}-{$store}-categories-a-1.xml";
        $this->assertSame(
            [$newPages, $categories, $product],
            array_keys($after),
            'The pages moved to their other set; the other types stayed in theirs.'
        );
        $this->assertStringContainsString($identifier, $this->pub()->readFile(self::DIRECTORY . '/' . $newPages));
        $this->assertNotSame($before[$pages], $after[$newPages], 'The rebuilt file has a new date.');
        $this->assertSame(
            $pagesBytes,
            $this->pub()->readFile(self::DIRECTORY . '/' . $pages),
            'The pages the previous index listed stay as they were until the next rebuild.'
        );
        $this->assertSame($before[$product], $after[$product], 'The kept file keeps its date.');
        $this->assertSame($productBytes, $this->pub()->readFile(self::DIRECTORY . '/' . $product));
        $this->assertSame($productTime, $this->mtime($product), 'The products file was not rewritten.');
    }

    /**
     * Files the rebuilt type no longer has go; the other types' files stay.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testLeftoversOfTheRebuiltTypeGoAndOtherTypesStay(): void
    {
        $sitemap = $this->generated();
        $store   = $this->defaultStoreId();
        $stray   = self::DIRECTORY . "/{$this->sitemapName}-{$store}-pages-9.xml";
        $this->pub()->writeFile($stray, '');

        $this->rebuilder()->rebuild('pages');

        $this->assertFalse($this->pub()->isExist($stray), 'A pages file the rebuild did not write is gone.');
        $this->assertTrue(
            $this->pub()->isExist(self::DIRECTORY . "/{$this->sitemapName}-{$store}-products-a-1.xml"),
            'The products file stays.'
        );
        $this->assertArrayNotHasKey("{$this->sitemapName}-{$store}-pages-9.xml", $this->index($sitemap));
    }

    /**
     * A sitemap configured but never generated has no files to keep: a change writes it whole.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testASitemapNeverGeneratedIsWrittenWhole(): void
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $sitemap->save();

        $this->rebuilder()->rebuild('pages');

        $this->assertArrayHasKey(
            "{$this->sitemapName}-{$this->defaultStoreId()}-products-a-1.xml",
            $this->index($sitemap),
            'Every type is written, not only the one the change was about.'
        );
    }

    /**
     * A sitemap last written by Magento's generator has no typed files to keep: it is written whole.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testASitemapLastWrittenByCoresGeneratorIsWrittenWhole(): void
    {
        $this->useGenerator(SitemapGenerator::MAGENTO);
        $sitemap = $this->generated();
        $this->useGenerator(SitemapGenerator::MAGEOS_SEO);

        $this->rebuilder()->rebuild('pages');

        $store = $this->defaultStoreId();
        $this->assertArrayHasKey("{$this->sitemapName}-{$store}-products-a-1.xml", $this->index($sitemap));
    }

    /**
     * A sitemap written before the sets — names without one — is written whole into the first set
     * by a rebuild of one type; the files of before stay while the index they replaced was the
     * last, and go at the rebuild after.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testASitemapWrittenBeforeTheSetsIsWrittenWholeAndItsFilesGoAfter(): void
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $sitemap->save();
        $store  = $this->defaultStoreId();
        $before = "{$this->sitemapName}-{$store}-products-1.xml";
        $this->pub()->writeFile(self::DIRECTORY . '/' . $before, '<urlset></urlset>');
        $this->pub()->writeFile(
            self::DIRECTORY . '/' . $sitemap->getSitemapFilename(),
            '<sitemapindex><sitemap><loc>http://localhost/media/sitemap/' . $before . '</loc>'
            . '<lastmod>2020-01-02T03:04:05+00:00</lastmod></sitemap></sitemapindex>'
        );

        $this->rebuilder()->rebuild('pages');

        $this->assertArrayHasKey("{$this->sitemapName}-{$store}-products-a-1.xml", $this->index($sitemap));
        $this->assertArrayNotHasKey($before, $this->index($sitemap));
        $this->assertTrue($this->pub()->isExist(self::DIRECTORY . '/' . $before), 'Kept while the old index was last.');

        $this->rebuilder()->rebuild('pages');

        $this->assertFalse($this->pub()->isExist(self::DIRECTORY . '/' . $before), 'Gone one rebuild later.');
    }

    /**
     * A store view that uses Magento's generator is not this module's to rebuild.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testASitemapOnMagentosGeneratorIsLeftAlone(): void
    {
        $this->useGenerator(SitemapGenerator::MAGENTO);
        $sitemap = $this->generated();
        $path    = self::DIRECTORY . '/' . $sitemap->getSitemapFilename();
        $before  = $this->pub()->readFile($path);

        $this->rebuilder()->rebuild('pages');

        $this->assertSame($before, $this->pub()->readFile($path));
    }

    /**
     * While another process writes the sitemap, a rebuild writes nothing and says so, so the queue
     * can ask again.
     *
     * @return void
     */
    public function testASitemapBeingWrittenElsewhereIsNotRebuilt(): void
    {
        $sitemap = $this->generated();
        $before  = $this->pub()->readFile(self::DIRECTORY . '/' . $sitemap->getSitemapFilename());

        $objectManager = Bootstrap::getObjectManager();
        $heldLock      = $objectManager->create(GenerationLock::class, ['lockManager' => new HeldSitemapLocks()]);
        $generator     = $objectManager->create(Generator::class, ['generationLock' => $heldLock]);

        try {
            $objectManager->create(Rebuilder::class, ['generator' => $generator])->rebuild('pages');
            $this->fail('A sitemap being written elsewhere was rebuilt.');
        } catch (SitemapRebuildInProgressException) {
            $this->assertSame(
                $before,
                $this->pub()->readFile(self::DIRECTORY . '/' . $sitemap->getSitemapFilename())
            );
        }
    }

    /**
     * The admin's Generate button and core's cron wait for another writer, then say what happened.
     *
     * @return void
     */
    public function testGenerateReportsASitemapBeingWrittenElsewhere(): void
    {
        Bootstrap::getObjectManager()->configure([
            'preferences' => [LockManagerInterface::class => HeldSitemapLocks::class],
        ]);

        $this->expectException(SitemapRebuildInProgressException::class);
        $this->expectExceptionMessage('is being written by another process');

        $this->generateSitemap($this->sitemapFor($this->defaultStoreId()));
    }

    /**
     * End to end: a product set NOINDEX through its override queues the products, and once the
     * queue's consumer has run, the product is no longer in the sitemap — the other types untouched.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testANoindexOverrideTakesTheProductOutOnceTheQueueRuns(): void
    {
        $sitemap  = $this->generated();
        $product  = DataFixtureStorageManager::getStorage()->get('product');
        $store    = $this->defaultStoreId();
        $products = self::DIRECTORY . '/' . $this->listedFile($sitemap, 'products');
        $pages    = "{$this->sitemapName}-{$store}-pages-a-1.xml";
        $this->assertStringContainsString((string) $product->getUrlKey(), $this->pub()->readFile($products));
        $pagesDate = $this->index($sitemap)[$pages];
        $flags     = Bootstrap::getObjectManager()->get(FlagManager::class);
        $flags->deleteFlag('mageos_seo_feed_pending_sitemap-products');

        sleep(1);
        Bootstrap::getObjectManager()->get(OverrideRepository::class)
            ->save((int) $product->getId(), 0, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->commitDeferredRequests();

        $this->assertNotNull($flags->getFlagData('mageos_seo_feed_pending_sitemap-products'), 'The change was queued.');
        Bootstrap::getObjectManager()->get(RegenerateConsumer::class)->process('sitemap-products');

        $this->assertStringNotContainsString(
            (string) $product->getUrlKey(),
            implode('', array_map(
                fn (string $file): string => $this->pub()->readFile(self::DIRECTORY . '/' . $file),
                array_keys($this->index($sitemap))
            )),
            'The NOINDEX product is out of the sitemap.'
        );
        $this->assertSame($pagesDate, $this->index($sitemap)[$pages], 'The pages were not rebuilt.');
    }

    /**
     * Rebuild on Change off: a change leaves the store view's sitemap to its next generation, and a
     * rebuild asked for by hand — the CLI command's — still rewrites it.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testWithRebuildOnChangeOffAChangeLeavesTheSitemapAndADemandRebuildsIt(): void
    {
        $sitemap = $this->generated();
        $this->setStoreConfig(Config::XML_SITEMAP_REBUILD_ON_CHANGE, '0');
        $identifier = $this->newPage();

        $this->rebuilder()->rebuild('pages');
        $pages = self::DIRECTORY . '/' . $this->listedFile($sitemap, 'pages');
        $this->assertStringNotContainsString($identifier, $this->pub()->readFile($pages), 'A change rebuilt it.');

        $this->rebuilder()->rebuildOnDemand('pages');
        $pages = self::DIRECTORY . '/' . $this->listedFile($sitemap, 'pages');
        $this->assertStringContainsString($identifier, $this->pub()->readFile($pages), 'Asked for, it is rebuilt.');
    }

    /**
     * A sitemap generated for the default store view, saved with its time.
     *
     * @return Sitemap
     */
    private function generated(): Sitemap
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $this->generateSitemap($sitemap);

        return $sitemap;
    }

    /**
     * @return Rebuilder
     */
    private function rebuilder(): Rebuilder
    {
        return Bootstrap::getObjectManager()->create(Rebuilder::class);
    }

    /**
     * @param string $file
     * @return int
     */
    private function mtime(string $file): int
    {
        clearstatcache();

        return (int) ($this->pub()->stat(self::DIRECTORY . '/' . $file)['mtime'] ?? 0);
    }

    /**
     * A new CMS page in every store view; returns its identifier.
     *
     * @return string
     */
    private function newPage(): string
    {
        $identifier = 'mageos-seo-rebuild-' . uniqid();

        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => 'MageOS SEO rebuild',
            PageInterface::CONTENT    => '<p>Rebuild</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        return $identifier;
    }
}
