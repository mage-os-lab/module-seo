<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\ObjectManager\ConfigInterface as ObjectManagerConfig;
use Magento\Sitemap\Model\Batch\Observer as BatchObserver;
use Magento\Sitemap\Model\ItemProvider\Composite as CoreComposite;
use Magento\Sitemap\Model\Observer as StandardObserver;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Config;
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
    private const DIRECTORY = 'media/sitemap';

    /**
     * The sitemap file name the running test writes under, without `.xml`.
     *
     * @var string|null
     */
    private ?string $baseName = null;

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
        $this->baseName = 'mageos_seo_f2_' . uniqid();
    }

    /**
     * Remove every file this test's sitemaps wrote, and the files it placed.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $pub = $this->pub();
        if ($pub->isExist(self::DIRECTORY)) {
            foreach ($pub->read(self::DIRECTORY) as $path) {
                if (str_starts_with(basename($path), (string) $this->baseName)) {
                    $pub->delete($path);
                }
            }
        }
        foreach ($this->placedFiles as $path) {
            if ($pub->isExist($path)) {
                $pub->delete($path);
            }
        }
        $this->placedFiles = [];
    }

    /**
     * The exit check for F2: the same `<url>` entries as core writes for the same store view.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testTheSameUrlsAsMagentosGeneratorWrites(): void
    {
        $this->useGenerator(SitemapGenerator::MAGENTO);
        $core = $this->urlRows($this->generate('_core'));

        $this->useGenerator(SitemapGenerator::MAGEOS_SEO);
        $ours = $this->urlRows($this->generate('_ours'));

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
        $storeId = $this->storeId();
        $files   = $this->generate('');
        $storage = DataFixtureStorageManager::getStorage();

        $this->assertSame(
            [
                "{$this->baseName}-{$storeId}-pages-1.xml",
                "{$this->baseName}-{$storeId}-categories-1.xml",
                "{$this->baseName}-{$storeId}-products-1.xml",
            ],
            array_keys($files)
        );
        $this->assertStringContainsString(
            (string) $storage->get('product')->getUrlKey(),
            $files["{$this->baseName}-{$storeId}-products-1.xml"]
        );
        $this->assertStringContainsString(
            (string) $storage->get('category')->getUrlKey(),
            $files["{$this->baseName}-{$storeId}-categories-1.xml"]
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
        $files = $this->generate('');

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
        $files = $this->generate('');

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
        $storeId   = $this->storeId();
        $leftovers = [
            "{$this->baseName}-{$storeId}-7.xml",
            "{$this->baseName}-{$storeId}-products-9.xml",
        ];
        $strangers = [
            "{$this->baseName}x-{$storeId}-1.xml",
            "{$this->baseName}-" . ($storeId + 100) . '-pages-1.xml',
            "unrelated_{$this->baseName}.xml",
        ];
        foreach (array_merge($leftovers, $strangers) as $name) {
            $this->place($name);
        }

        $this->generate('');

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

        $files = $this->generate('');

        $other = "{$this->baseName}-{$this->storeId()}-other-1.xml";
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

        $this->generate('');

        $index = $this->pub()->readFile(self::DIRECTORY . "/{$this->baseName}.xml");
        $this->assertStringContainsString('<urlset', $index);
        $this->assertStringNotContainsString('<sitemapindex', $index);
    }

    /**
     * Both of core's cron jobs — standard and batch — end up in the selected generator.
     *
     * @magentoConfigFixture current_store sitemap/generate/enabled 1
     * @return void
     */
    public function testBothOfCoresCronJobsUseTheSelectedGenerator(): void
    {
        $sitemap = $this->sitemap('');
        $sitemap->save();
        $index = self::DIRECTORY . "/{$this->baseName}.xml";

        foreach ([StandardObserver::class, BatchObserver::class] as $observer) {
            if ($this->pub()->isExist($index)) {
                $this->pub()->delete($index);
            }

            Bootstrap::getObjectManager()->create($observer)->scheduledGenerateSitemaps();

            $this->assertTrue($this->pub()->isExist($index), $observer . ' wrote no sitemap.');
            $this->assertStringContainsString(
                '<sitemapindex',
                $this->pub()->readFile($index),
                $observer . ' did not go through this module\'s generator.'
            );
        }
    }

    /**
     * @return void
     */
    public function testTheSitemapIsSavedWithItsGenerationTime(): void
    {
        $sitemap = $this->sitemap('');
        $this->generateSitemap($sitemap);

        $this->assertNotEmpty($sitemap->getSitemapTime());
        $this->assertNotEmpty($sitemap->getId(), 'The sitemap was not saved.');
    }

    /**
     * Generate a sitemap for the default store view and return its URL files, keyed by name.
     *
     * For an index, the files it lists; for core's single file, that file.
     *
     * @param string $suffix Added to the file name, so two generations in one test do not collide
     * @return array<string,string>
     */
    private function generate(string $suffix): array
    {
        $sitemap = $this->sitemap($suffix);
        $this->generateSitemap($sitemap);

        $index = $this->pub()->readFile(self::DIRECTORY . '/' . $sitemap->getSitemapFilename());
        if (!str_contains($index, '<sitemapindex')) {
            return [(string) $sitemap->getSitemapFilename() => $index];
        }

        preg_match_all('#<loc>[^<]*/([^/<]+\.xml)</loc>#', $index, $matches);
        $files = [];
        foreach ($matches[1] as $name) {
            $files[$name] = $this->pub()->readFile(self::DIRECTORY . '/' . $name);
        }

        return $files;
    }

    /**
     * Run core's entry point under the store view's frontend, as its controller and cron do.
     *
     * @param Sitemap $sitemap
     * @return void
     */
    private function generateSitemap(Sitemap $sitemap): void
    {
        $emulation = Bootstrap::getObjectManager()->get(Emulation::class);
        $emulation->startEnvironmentEmulation($this->storeId(), Area::AREA_FRONTEND, true);
        try {
            $sitemap->generateXml();
        } finally {
            $emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * A sitemap for the default store view in pub/media/sitemap.
     *
     * @param string $suffix
     * @return Sitemap
     */
    private function sitemap(string $suffix): Sitemap
    {
        /** @var Sitemap $sitemap */
        $sitemap = Bootstrap::getObjectManager()->create(Sitemap::class);
        $sitemap->setData([
            'sitemap_filename' => $this->baseName . $suffix . '.xml',
            'sitemap_path'     => '/' . self::DIRECTORY . '/',
            'store_id'         => $this->storeId(),
        ]);

        return $sitemap;
    }

    /**
     * Every `<url>` row of the given files.
     *
     * @param array<string,string> $files
     * @return string[]
     */
    private function urlRows(array $files): array
    {
        $rows = [];
        foreach ($files as $xml) {
            preg_match_all('#<url>.*?</url>#s', $xml, $matches);
            $rows[] = $matches[0];
        }

        return array_merge([], ...$rows);
    }

    /**
     * Select a generator for the default store view.
     *
     * At store scope: the setting is read for the sitemap's store view, and the test configuration
     * merges store values when it loads, so a default-scope change would never reach that read.
     *
     * @param string $generator
     * @return void
     */
    private function useGenerator(string $generator): void
    {
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue(Config::XML_SITEMAP_GENERATOR, $generator, 'store', 'default');
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

    /**
     * @return WriteInterface
     */
    private function pub(): WriteInterface
    {
        return Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::PUB);
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
