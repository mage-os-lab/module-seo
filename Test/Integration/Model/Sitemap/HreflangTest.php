<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * F3: hreflang alternates inline in the sitemap, each URL listing the others and itself.
 *
 * Two store views of one website — the default one in en-US, a German one on its own host — and
 * real sitemaps generated for each through core's entry point.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class HreflangTest extends TestCase
{
    use GeneratesSitemaps;

    private const GERMAN_HOST = 'http://de.localhost/';

    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

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

        $pageRepository = Bootstrap::getObjectManager()->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // Already gone.
            }
        }
        $this->createdPageIds = [];
    }

    /**
     * Every URL with alternates lists itself among them — Google discards a set that does not —
     * and a product lists both store views.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$category.id$']], as: 'product')]
    public function testEveryUrlListsItsAlternatesItsOwnAmongThem(): void
    {
        $this->twoLanguages();

        $rows    = $this->parsed($this->generateFor($this->defaultStoreId()));
        $product = $this->rowFor($rows, $this->productUrlKey());

        $withAlternates = array_filter($rows, static fn (array $row): bool => $row['links'] !== []);
        $this->assertNotEmpty($withAlternates);
        foreach ($withAlternates as $row) {
            $this->assertContains($row['loc'], $row['links'], $row['loc'] . ' does not list itself.');
        }

        $this->assertSame($product['loc'], $product['links']['en-US'] ?? null);
        $this->assertStringStartsWith(self::GERMAN_HOST, (string) ($product['links']['de-DE'] ?? ''));
    }

    /**
     * The German sitemap lists the same alternates from its side.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testTheOtherStoreViewsSitemapPointsBack(): void
    {
        $german = $this->twoLanguages();

        $english = $this->rowFor(
            $this->parsed($this->generateFor($this->defaultStoreId(), '_en')),
            $this->productUrlKey()
        );
        $deutsch = $this->rowFor($this->parsed($this->generateFor($german, '_de')), $this->productUrlKey());

        $this->assertStringStartsWith(self::GERMAN_HOST, $deutsch['loc']);
        $this->assertSame($deutsch['loc'], $deutsch['links']['de-DE'] ?? null);
        $this->assertSame($english['loc'], $deutsch['links']['en-US'] ?? null);
        $this->assertSame($english['links'], $deutsch['links'], 'Both sides of a set must list the same set.');
    }

    /**
     * A CMS page lists its translation in the other store view.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    public function testCmsTranslationsAreAlternatesOfEachOther(): void
    {
        $german = $this->twoLanguages();
        $group  = 'mageos-seo-sitemap-' . uniqid();
        $about  = $this->page([$this->defaultStoreId()], $group);
        $ueber  = $this->page([$german], $group);

        $row = $this->rowFor($this->parsed($this->generateFor($this->defaultStoreId())), $about);

        $this->assertSame(self::GERMAN_HOST . 'index.php/' . $ueber, $row['links']['de-DE'] ?? null);
    }

    /**
     * Alternates use the store view's configured scheme. Generation runs from cron — the command
     * line, with no secure request — and the base URL used to follow the request, so an https store
     * view's alternates came out http while its `<loc>` was https, and matched nothing.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAnHttpsStoreViewsAlternatesAreHttps(): void
    {
        $german = $this->twoLanguages();
        $code   = (string) DataFixtureStorageManager::getStorage()->get('german')->getCode();
        $this->setStoreConfig('web/secure/base_url', 'https://de.localhost/', $code);
        $this->setStoreConfig('web/secure/base_link_url', 'https://de.localhost/', $code);
        $this->setStoreConfig('web/secure/use_in_frontend', '1', $code);

        $row = $this->rowFor($this->parsed($this->generateFor($german)), $this->productUrlKey());

        $this->assertStringStartsWith('https://de.localhost/', $row['loc']);
        $this->assertSame($row['loc'], $row['links']['de-DE'] ?? null);
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testEveryFileDeclaresTheXhtmlNamespace(): void
    {
        $this->twoLanguages();

        foreach ($this->generateFor($this->defaultStoreId()) as $name => $xml) {
            $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml, $name);
        }
    }

    /**
     * @magentoConfigFixture default/mageos_seo_general/hreflang/sitemap_enabled 0
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testNoAlternatesAreWrittenWhenTheSettingIsOff(): void
    {
        $this->twoLanguages();

        foreach ($this->generateFor($this->defaultStoreId()) as $name => $xml) {
            $this->assertStringNotContainsString('<xhtml:link', $xml, $name);
        }
    }

    /**
     * Make the fixture store view German, on its own host; return its ID.
     *
     * @return int
     */
    private function twoLanguages(): int
    {
        $german = DataFixtureStorageManager::getStorage()->get('german');
        $code   = (string) $german->getCode();

        $this->setStoreConfig(Config::XML_HREFLANG_CODES, 'de-DE', $code);
        $this->setStoreConfig('web/unsecure/base_url', self::GERMAN_HOST, $code);
        $this->setStoreConfig('web/unsecure/base_link_url', self::GERMAN_HOST, $code);

        return (int) $german->getId();
    }

    /**
     * Each row's `<loc>` and its alternates, hreflang => href.
     *
     * @param array<string,string> $files
     * @return array<int,array{loc:string,links:array<string,string>}>
     */
    private function parsed(array $files): array
    {
        $rows = [];
        foreach ($this->urlRows($files) as $row) {
            preg_match('#<loc>([^<]*)</loc>#', $row, $loc);
            preg_match_all('#<xhtml:link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/>#', $row, $links);
            $rows[] = [
                'loc'   => html_entity_decode($loc[1] ?? ''),
                'links' => array_combine($links[1], array_map('html_entity_decode', $links[2])),
            ];
        }

        return $rows;
    }

    /**
     * The row whose `<loc>` ends with the given path.
     *
     * @param array<int,array{loc:string,links:array<string,string>}> $rows
     * @param string $path
     * @return array{loc:string,links:array<string,string>}
     */
    private function rowFor(array $rows, string $path): array
    {
        foreach ($rows as $row) {
            if (str_ends_with($row['loc'], '/' . $path) || str_ends_with($row['loc'], '/' . $path . '.html')) {
                return $row;
            }
        }

        $this->fail('No row for ' . $path . '.');
    }

    /**
     * @return string
     */
    private function productUrlKey(): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get('product')->getUrlKey();
    }

    /**
     * A CMS page in the given store views and translation group; returns its identifier.
     *
     * @param int[] $storeIds
     * @param string $group
     * @return string
     */
    private function page(array $storeIds, string $group): string
    {
        $identifier = 'mageos-seo-sitemap-' . uniqid();

        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => 'MageOS SEO sitemap translation',
            PageInterface::CONTENT    => '<p>Translation</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => $storeIds,
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;
        Bootstrap::getObjectManager()->get(ConfigRepository::class)->save($pageId, ['hreflang_group' => $group]);

        return $identifier;
    }
}
