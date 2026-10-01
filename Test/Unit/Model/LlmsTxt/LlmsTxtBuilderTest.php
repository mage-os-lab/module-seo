<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\LlmsTxt;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\LlmsTxt\SectionProviderInterface;
use MageOS\Seo\Model\LlmsTxt\SitemapUrlResolver;
use MageOS\Seo\Model\Organization\ContactEmail;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Checks the generated documents against the llms.txt format (https://llmstxt.org)
 * and against the three checks of Lighthouse's llms-txt audit.
 *
 * Doubles Magento's generated category CollectionFactory, so it runs in the unit job
 * inside an installation, not under Infection.
 */
#[Group('magento-generated')]
class LlmsTxtBuilderTest extends TestCase
{
    /**
     * Lighthouse llms-txt audit (core/audits/agentic/llms-txt.js): an H1, at least one
     * markdown link, and at least 50 characters.
     */
    private const LIGHTHOUSE_H1   = '/^\s*#\s+.+/m';
    private const LIGHTHOUSE_LINK = '/\[.+\]\(.+\)/';
    private const LIGHTHOUSE_MIN_LENGTH = 50;

    /**
     * One llms.txt "file list" item: "- [name](url)" with optional ": notes"; nesting allowed.
     * The label and URL classes match the reference llms_txt parser ([^\]]+ and [^\)]+).
     */
    private const FILE_LIST_ITEM = '/^(  )*- \[[^\]]+\]\([^)\s]+\)(: .+)?$/';

    /**
     * @var OrganizationInterface&MockObject
     */
    private OrganizationInterface&MockObject $organization;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private ScopeConfigInterface&MockObject $scopeConfig;

    /**
     * @var CategoryCollectionFactory&MockObject
     */
    private CategoryCollectionFactory&MockObject $categoryCollectionFactory;

    /**
     * @var SchemaBuilderPool&MockObject
     */
    private SchemaBuilderPool&MockObject $builderPool;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface&MockObject $storeManager;

    private ?string $sitemapUrl = 'https://shop.test/media/sitemap.xml';

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $store->method('getName')->willReturn('Default Store View');
        $store->method('getRootCategoryId')->willReturn(2);

        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(1);

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->storeManager->method('getWebsite')->willReturn($website);

        $this->organization = $this->createMock(OrganizationInterface::class);
        $this->organization->method('getName')->willReturn('Test Shop');

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['catalog/seo/category_url_suffix', 'store', 1, '.html'],
        ]);

        $this->builderPool = $this->createMock(SchemaBuilderPool::class);
        $this->builderPool->method('getAvailableTemplates')->willReturn([
            'GenericProduct' => 'Generic product',
            'Book'           => 'Book',
        ]);

        $this->categoryCollectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $this->categoryCollectionFactory->method('create')->willReturn($this->categoryCollection([
            ['id' => 3, 'parent_id' => 2, 'level' => 2, 'name' => 'Clothing',
                'url_path' => 'clothing', 'product_count' => 245],
            ['id' => 4, 'parent_id' => 3, 'level' => 3, 'name' => "Men's [Sale]",
                'url_path' => 'clothing/mens-sale', 'product_count' => 0],
            ['id' => 7, 'parent_id' => 2, 'level' => 2, 'name' => 'Kids',
                'url_path' => 'kids (new)', 'product_count' => 3],
            // Child of an inactive parent (5 is not in the collection): must be skipped.
            ['id' => 6, 'parent_id' => 5, 'level' => 3, 'name' => 'Hidden',
                'url_path' => 'hidden/child', 'product_count' => 9],
        ]));
    }

    public function testConciseDocumentPassesLighthouseChecks(): void
    {
        $this->assertLighthouseChecksPass($this->builder()->buildConcise());
    }

    public function testFullDocumentPassesLighthouseChecks(): void
    {
        $this->assertLighthouseChecksPass($this->builder()->buildFull());
    }

    public function testConciseDocumentFollowsLlmsTxtFormat(): void
    {
        $this->organization->method('getDescription')->willReturn('Handmade ceramics from Rotterdam.');

        $document = $this->builder()->buildConcise();

        $this->assertLlmsTxtFormat($document);
        $this->assertStringContainsString('- [Home](https://shop.test/): Store front page', $document);
        $this->assertStringContainsString('- Contact for automated queries: <support@shop.test>', $document);
        $this->assertStringContainsString("\n- Locale: en_GB\n", $document);
        $this->assertStringContainsString(
            '- Structured data: schema.org JSON-LD on product pages (Product, Book)',
            $document
        );
        $this->assertStringNotContainsString('GenericProduct', $document);
    }

    public function testFullDocumentFollowsLlmsTxtFormat(): void
    {
        $this->organization->method('getDescription')->willReturn('Handmade ceramics from Rotterdam.');
        $this->organization->method('getSocialProfiles')->willReturn(['https://social.test/shop']);

        $document = $this->builder()->buildFull();

        $this->assertLlmsTxtFormat($document);
        $this->assertStringContainsString('- Social profiles: <https://social.test/shop>', $document);
        $this->assertStringContainsString(
            '- Structured data: schema.org JSON-LD; types the pages can carry: Organization, WebSite, '
            . 'CollectionPage, BreadcrumbList, ItemList, Product, Book',
            $document
        );
        $this->assertStringNotContainsString('in use', $document);
    }

    public function testSummaryIsOneLine(): void
    {
        $this->organization->method('getDescription')->willReturn("Handmade ceramics.\n\nSince 2004.");

        $document = $this->builder()->buildConcise();

        $this->assertStringContainsString("\n> Handmade ceramics. Since 2004.\n", $document);
    }

    public function testNoBlockquoteWithoutDescription(): void
    {
        $this->organization->method('getDescription')->willReturn('   ');

        $document = $this->builder()->buildConcise();

        $this->assertDoesNotMatchRegularExpression('/^>/m', $document);
        $this->assertLlmsTxtFormat($document);
    }

    public function testCategoryTreeUsesParserSafeLinksAndSkipsHiddenSubtrees(): void
    {
        $document = $this->builder()->buildFull();

        $this->assertStringContainsString(
            '- [Clothing](https://shop.test/clothing.html): 245 products',
            $document
        );
        $this->assertStringContainsString(
            '  - [Men\'s (Sale)](https://shop.test/clothing/mens-sale.html)' . "\n",
            $document
        );
        $this->assertStringContainsString('(https://shop.test/kids%20%28new%29.html)', $document);
        $this->assertStringNotContainsString('Hidden', $document);
    }

    public function testACategoryReadFailureIsNotSwallowed(): void
    {
        // FeedRegenerator logs a store's failed build and keeps the previous file;
        // publishing a document without its category tree would hide the failure.
        $factory = $this->createMock(CategoryCollectionFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('db down'));
        $this->categoryCollectionFactory = $factory;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');

        $this->builder()->buildFull();
    }

    public function testNoCategoryTreeWhenTheStoreHasNoVisibleCategories(): void
    {
        $factory = $this->createMock(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($this->categoryCollection([]));
        $this->categoryCollectionFactory = $factory;

        $document = $this->builder()->buildFull();

        $this->assertStringNotContainsString('## Category Tree', $document);
        $this->assertLlmsTxtFormat($document);
    }

    public function testProviderProseGoesBeforeFirstH2AndSectionsAfter(): void
    {
        $prose   = $this->provider("Frequently asked questions:\n\n- **Q1** A1");
        $section = $this->provider("## Vendors\n\n- [Studio A](https://shop.test/studio-a): Ceramics");

        $document = $this->builder([$section, $prose])->buildConcise();

        $firstH2 = strpos($document, "\n## ");
        $this->assertLessThan($firstH2, strpos($document, 'Frequently asked questions:'));
        $this->assertGreaterThan(strpos($document, '## Key URLs'), strpos($document, '## Vendors'));
        $this->assertLlmsTxtFormat($document);
    }

    public function testSitemapLinkUsesTheConfiguredSitemap(): void
    {
        $document = $this->builder()->buildConcise();

        $this->assertStringContainsString(
            '- [Sitemap](https://shop.test/media/sitemap.xml): XML sitemap of indexable pages',
            $document
        );
        $this->assertStringNotContainsString('https://shop.test/sitemap.xml', $document);
    }

    public function testNoSitemapLinkWithoutAGeneratedSitemap(): void
    {
        $this->sitemapUrl = null;

        $document = $this->builder()->buildConcise();

        $this->assertStringNotContainsString('[Sitemap]', $document);
        $this->assertLlmsTxtFormat($document);
    }

    /**
     * @param SectionProviderInterface[] $providers
     * @return LlmsTxtBuilder
     */
    private function builder(array $providers = []): LlmsTxtBuilder
    {
        $repository = $this->createMock(OrganizationRepositoryInterface::class);
        $repository->method('getForScope')->willReturn($this->organization);
        $seoConfig = $this->createMock(Config::class);
        $seoConfig->method('getLocaleCode')->willReturnMap([[1, 'en_GB']]);
        $contactEmail = $this->createMock(ContactEmail::class);
        $contactEmail->method('get')->willReturn('support@shop.test');
        $sitemapResolver = $this->createMock(SitemapUrlResolver::class);
        $sitemapResolver->method('getUrl')->willReturn($this->sitemapUrl);

        return new LlmsTxtBuilder(
            $repository,
            $this->storeManager,
            $this->scopeConfig,
            $this->categoryCollectionFactory,
            $this->builderPool,
            $seoConfig,
            $contactEmail,
            $sitemapResolver,
            $providers
        );
    }

    /**
     * @param string $markdown
     * @return SectionProviderInterface
     */
    private function provider(string $markdown): SectionProviderInterface
    {
        $provider = $this->createMock(SectionProviderInterface::class);
        $provider->method('getConciseSection')->willReturn($markdown);
        $provider->method('getFullSection')->willReturn($markdown);

        return $provider;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return CategoryCollection&MockObject
     */
    private function categoryCollection(array $rows): CategoryCollection&MockObject
    {
        $items = array_map(static fn (array $row): DataObject => new DataObject($row), $rows);

        $collection = $this->createMock(CategoryCollection::class);
        $fluent = ['setStoreId', 'addAttributeToSelect', 'addPathsFilter', 'addAttributeToFilter', 'setOrder'];
        foreach ($fluent as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('getItems')->willReturn($items);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        return $collection;
    }

    /**
     * @param string $document
     * @return void
     */
    private function assertLighthouseChecksPass(string $document): void
    {
        $this->assertMatchesRegularExpression(self::LIGHTHOUSE_H1, $document);
        $this->assertMatchesRegularExpression(self::LIGHTHOUSE_LINK, $document);
        $this->assertGreaterThanOrEqual(self::LIGHTHOUSE_MIN_LENGTH, \strlen($document));
    }

    /**
     * Assert the llms.txt section order and that every H2 section is a file list.
     *
     * @param string $document
     * @return void
     */
    private function assertLlmsTxtFormat(string $document): void
    {
        $lines = explode("\n", rtrim($document, "\n"));

        $this->assertMatchesRegularExpression('/^# \S/', $lines[0], 'The first line must be the H1.');

        $inFileList = false;
        foreach (\array_slice($lines, 1) as $number => $line) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(#|###+)\s/',
                $line,
                'Only one H1 and no headings below H2 are allowed (line ' . ($number + 2) . ').'
            );
            if (str_starts_with($line, '## ')) {
                $inFileList = true;
                continue;
            }
            if (str_starts_with($line, '>')) {
                $this->assertFalse($inFileList, 'The summary must come before the first H2.');
                $this->assertSame('', $lines[$number], 'The summary must directly follow the H1.');
            }
            if ($inFileList && $line !== '') {
                $this->assertMatchesRegularExpression(
                    self::FILE_LIST_ITEM,
                    $line,
                    'Every line under an H2 must be a "- [name](url)" item (line ' . ($number + 2) . ').'
                );
            }
        }
    }
}
