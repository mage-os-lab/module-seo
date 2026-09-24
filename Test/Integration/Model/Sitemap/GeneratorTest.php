<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\ObjectManager\ConfigInterface as ObjectManagerConfig;
use Magento\Sitemap\Model\ItemProvider\Composite as CoreComposite;
use Magento\Sitemap\Model\Observer as StandardObserver;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use MageOS\Seo\Model\Config\Source\SitemapGenerator;
use MageOS\Seo\Test\Integration\Model\Sitemap\Fixture\RegisteredElsewhereProvider;
use PHPUnit\Framework\TestCase;

/**
 * F2: this module's generator writes what core's writes, laid out one file per type under an index.
 *
 * Every test generates a real sitemap into pub/media/sitemap through core's own entry point,
 * `Sitemap::generateXml()`, and reads the files back.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class GeneratorTest extends TestCase
{
    use GeneratesSitemaps;

    private const DIRECTORY = 'media/sitemap';

    /**
     * Files the running test put in place itself, relative to pub/.
     *
     * @var string[]|null
     */
    private ?array $placedFiles = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->setUpSitemaps();
    }

    /**
     * Remove every file this test's sitemaps wrote, and the files it placed.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeGeneratedSitemaps();
        foreach ($this->placedFiles as $path) {
            if ($this->pub()->isExist($path)) {
                $this->pub()->delete($path);
            }
        }
        $this->placedFiles = [];
    }

    /**
     * The exit check for F2: the same `<url>` entries as core writes for the same store view.
     *
     * Alternates are this generator's addition, so they are taken out of its rows before comparing:
     * what is left must be core's row exactly.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testTheSameUrlsAsMagentosGeneratorWrites(): void
    {
        $this->useGenerator(SitemapGenerator::MAGENTO);
        $core = $this->urlRows($this->generateFor($this->defaultStoreId(), '_core'));

        $this->useGenerator(SitemapGenerator::MAGEOS_SEO);
        $ours = array_map(
            static fn (string $row): string => (string) preg_replace('#<xhtml:link [^>]*/>#', '', $row),
            $this->urlRows($this->generateFor($this->defaultStoreId(), '_ours'))
        );

        $productUrlKey = (string) DataFixtureStorageManager::getStorage()->get('product')->getUrlKey();
        $this->assertNotEmpty(
            array_filter($core, static fn (string $row) => str_contains($row, $productUrlKey)),
            'The comparison is only worth something if the catalogue is in it.'
        );
        $this->assertEqualsCanonicalizing($core, $ours);
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testTheSitemapIsAnIndexOfOneFileSetPerType(): void
    {
        $storeId = $this->defaultStoreId();
        $files   = $this->generateFor($storeId);
        $storage = DataFixtureStorageManager::getStorage();

        $this->assertSame(
            [
                "{$this->sitemapName}-{$storeId}-pages-1.xml",
                "{$this->sitemapName}-{$storeId}-categories-1.xml",
                "{$this->sitemapName}-{$storeId}-products-1.xml",
            ],
            array_keys($files)
        );
        $this->assertStringContainsString(
            (string) $storage->get('product')->getUrlKey(),
            $files["{$this->sitemapName}-{$storeId}-products-1.xml"]
        );
        $this->assertStringContainsString(
            (string) $storage->get('category')->getUrlKey(),
            $files["{$this->sitemapName}-{$storeId}-categories-1.xml"]
        );
    }

    /**
     * @magentoConfigFixture default_store sitemap/limit/max_lines 2
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'first')]
    #[DataFixture(ProductFixture::class, as: 'second')]
    #[DataFixture(ProductFixture::class, as: 'third')]
    public function testAFileEndsAtTheConfiguredNumberOfUrls(): void
    {
        $files = $this->generateFor($this->defaultStoreId());

        $products = array_filter(
            $files,
            static fn (string $name) => str_contains($name, '-products-'),
            ARRAY_FILTER_USE_KEY
        );
        $this->assertGreaterThan(1, \count($products), 'Three products and more cannot fit two to a file.');
        foreach ($files as $name => $xml) {
            $this->assertLessThanOrEqual(2, \count($this->urlRows([$name => $xml])), $name . ' holds too many URLs.');
        }
    }

    /**
     * A file ends before the row that would take it past the configured size — the row measured as
     * written, so no file ever passes it.
     *
     * The one exception is a single row larger than the limit on its own: it cannot be split, so it
     * gets a file to itself. At a real limit (megabytes) no row comes near; at this test's it does.
     *
     * @magentoConfigFixture default_store sitemap/limit/max_file_size 1500
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'first')]
    #[DataFixture(ProductFixture::class, as: 'second')]
    #[DataFixture(ProductFixture::class, as: 'third')]
    public function testNoFileGrowsPastTheConfiguredSize(): void
    {
        $files = $this->generateFor($this->defaultStoreId());

        $this->assertGreaterThan(3, \count($files), 'The size limit has to split something to prove anything.');
        foreach ($files as $name => $xml) {
            if (\strlen($xml) > 1500) {
                $this->assertCount(
                    1,
                    $this->urlRows([$name => $xml]),
                    $name . ' is larger than the limit and holds more than the one row that forced it.'
                );
            }
        }
    }

    /**
     * Earlier files of this sitemap that the new set does not contain are removed; nothing else is.
     *
     * @return void
     */
    public function testLeftoversOfThisSitemapGoAndNothingElseDoes(): void
    {
        $storeId   = $this->defaultStoreId();
        $leftovers = [
            "{$this->sitemapName}-{$storeId}-7.xml",
            "{$this->sitemapName}-{$storeId}-products-9.xml",
        ];
        $strangers = [
            "{$this->sitemapName}x-{$storeId}-1.xml",
            "{$this->sitemapName}-" . ($storeId + 100) . '-pages-1.xml',
            "unrelated_{$this->sitemapName}.xml",
        ];
        foreach (array_merge($leftovers, $strangers) as $name) {
            $this->place($name);
        }

        $this->generateFor($storeId);

        foreach ($leftovers as $name) {
            $this->assertFalse($this->pub()->isExist(self::DIRECTORY . '/' . $name), $name . ' was left behind.');
        }
        foreach ($strangers as $name) {
            $this->assertTrue($this->pub()->isExist(self::DIRECTORY . '/' . $name), $name . ' was removed.');
        }
    }

    /**
     * @return void
     */
    public function testAProviderRegisteredOnlyWithCoreIsWrittenUnderOther(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $arguments     = $objectManager->get(ObjectManagerConfig::class)->getArguments(CoreComposite::class);
        $arguments['itemProviders']['mageosSeoRegisteredElsewhere'] = [
            'instance' => RegisteredElsewhereProvider::class,
        ];
        $objectManager->configure([CoreComposite::class => ['arguments' => $arguments]]);

        $files = $this->generateFor($this->defaultStoreId());

        $other = "{$this->sitemapName}-{$this->defaultStoreId()}-other-1.xml";
        $this->assertArrayHasKey($other, $files);
        $this->assertStringContainsString(RegisteredElsewhereProvider::URL, $files[$other]);
    }

    /**
     * With Magento's generator selected, core writes as it always did: one `<urlset>`, no index.
     *
     * @return void
     */
    public function testWithMagentoSelectedCoreGenerates(): void
    {
        $this->useGenerator(SitemapGenerator::MAGENTO);

        $this->generateFor($this->defaultStoreId());

        $index = $this->pub()->readFile(self::DIRECTORY . "/{$this->sitemapName}.xml");
        $this->assertStringContainsString('<urlset', $index);
        $this->assertStringNotContainsString('<sitemapindex', $index);
    }

    /**
     * Core's cron job ends up in the selected generator.
     *
     * Only the standard job, which every supported version has. With this module's generator
     * selected, generation goes through it whatever core's Generation Method says; on 2.4.9, where
     * core also has a batch job, that one reaches it through the same plugin, which its sitemap
     * class inherits.
     *
     * The cron job catches every exception and only reports it by e-mail, so an error address is
     * configured and the captured mail is what says why a sitemap is missing.
     *
     * @magentoConfigFixture current_store sitemap/generate/enabled 1
     * @magentoConfigFixture current_store sitemap/generate/error_email errors@example.com
     * @return void
     */
    public function testCoresCronJobUsesTheSelectedGenerator(): void
    {
        $this->sitemapFor($this->defaultStoreId())->save();
        $index = self::DIRECTORY . "/{$this->sitemapName}.xml";

        Bootstrap::getObjectManager()->create(StandardObserver::class)->scheduledGenerateSitemaps();

        $sent = Bootstrap::getObjectManager()->get(TransportBuilderMock::class)->getSentMessage();
        $this->assertNull($sent, 'The cron job reported errors: ' . ($sent === null ? '' : $sent->getBodyText()));
        $this->assertTrue($this->pub()->isExist($index), 'The cron job wrote no sitemap.');
        $this->assertStringContainsString(
            '<sitemapindex',
            $this->pub()->readFile($index),
            'The cron job did not go through this module\'s generator.'
        );
    }

    /**
     * @return void
     */
    public function testTheSitemapIsSavedWithItsGenerationTime(): void
    {
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $this->generateSitemap($sitemap);

        $this->assertNotEmpty($sitemap->getSitemapTime());
        $this->assertNotEmpty($sitemap->getId(), 'The sitemap was not saved.');
    }

    /**
     * Put an empty file in the sitemap directory, to see whether generation removes it.
     *
     * @param string $name
     * @return void
     */
    private function place(string $name): void
    {
        $path = self::DIRECTORY . '/' . $name;
        $this->pub()->writeFile($path, '<urlset/>');
        $this->placedFiles[] = $path;
    }
}
