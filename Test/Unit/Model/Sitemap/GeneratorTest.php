<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface as CoreItemProviderInterface;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Api\Sitemap\ItemEnricherInterface;
use MageOS\Seo\Api\Sitemap\ItemFilterInterface;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Api\Sitemap\RowRendererInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\Generator;
use MageOS\Seo\Model\Sitemap\ItemProvider\Composite;
use MageOS\Seo\Model\Sitemap\ItemProvider\CoreProviderAdapter;
use MageOS\Seo\Model\Sitemap\ItemProvider\CoreProviderAdapterFactory;
use MageOS\Seo\Model\Sitemap\SitemapItem;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;
use MageOS\Seo\Model\Sitemap\Writer;
use MageOS\Seo\Model\Sitemap\WriterFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The pipeline: which rows go in which type, in what order, through which extensions. That the
 * files it writes match core's is covered against a real install by
 * Test/Integration/Model/Sitemap/GeneratorTest.
 *
 * The writer and adapter factories are Magento's generated classes, so this test needs an
 * installation to have generated them. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class GeneratorTest extends TestCase
{
    /**
     * What the writer was asked to do, in order.
     *
     * @var string[]
     */
    private array $written = [];

    /**
     * The arguments the writer was created with.
     *
     * @var array<string,mixed>
     */
    private array $writerArguments = [];

    protected function setUp(): void
    {
        $this->written         = [];
        $this->writerArguments = [];
    }

    public function testTypesAreWrittenPagesCategoriesProductsThenOwnTypesThenOther(): void
    {
        $this->generator([
            'vendor'   => $this->provider('vendors', ['v.html']),
            'product'  => $this->provider(ItemProviderInterface::TYPE_PRODUCTS, ['p.html']),
            'other'    => $this->provider(ItemProviderInterface::TYPE_OTHER, ['o.html']),
            'category' => $this->provider(ItemProviderInterface::TYPE_CATEGORIES, ['c.html']),
            'blog'     => $this->provider('blog-posts', ['b.html']),
            'page'     => $this->provider(ItemProviderInterface::TYPE_PAGES, ['a.html']),
        ])->generate($this->sitemap());

        $this->assertSame(
            [
                'type:pages', 'row:a.html',
                'type:categories', 'row:c.html',
                'type:products', 'row:p.html',
                'type:blog-posts', 'row:b.html',
                'type:vendors', 'row:v.html',
                'type:other', 'row:o.html',
                'finish',
            ],
            $this->written
        );
    }

    public function testAProviderRegisteredOnlyWithCoreIsWrittenAsOther(): void
    {
        $core = $this->createStub(CoreItemProviderInterface::class);
        $core->method('getItems')->willReturn([new SitemapItem('elsewhere.html', '0.5', 'daily')]);

        $this->generator(['elsewhere' => $core])->generate($this->sitemap());

        $this->assertSame(['type:other', 'row:elsewhere.html', 'finish'], $this->written);
    }

    public function testATypeThatCannotBePartOfAFileNameGoesToOtherWithAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('Blog Posts'));

        $this->generator(['blog' => $this->provider('Blog Posts', ['b.html'])], null, [], [], $logger)
            ->generate($this->sitemap());

        $this->assertSame(['type:other', 'row:b.html', 'finish'], $this->written);
    }

    public function testEachRowIsEveryRenderersOutputInOrderInsideUrl(): void
    {
        $this->generator(
            ['page' => $this->provider(ItemProviderInterface::TYPE_PAGES, ['a.html'])],
            [$this->renderer('<first/>'), $this->renderer(''), $this->renderer('<second/>')]
        )->generate($this->sitemap());

        $this->assertContains('row:<first/><second/>', $this->written);
    }

    public function testAnItemAFilterRejectsIsNotWritten(): void
    {
        $filter = $this->createStub(ItemFilterInterface::class);
        $filter->method('isIncluded')->willReturnCallback(
            static fn (SitemapItemInterface $item): bool => $item->getUrl() !== 'noindex.html'
        );

        $this->generator(
            ['page' => $this->provider(ItemProviderInterface::TYPE_PAGES, ['a.html', 'noindex.html', 'b.html'])],
            null,
            [],
            [$filter]
        )->generate($this->sitemap());

        $this->assertSame(['type:pages', 'row:a.html', 'row:b.html', 'finish'], $this->written);
    }

    public function testEnrichersSeeTheItemsAChunkAtATime(): void
    {
        $chunks   = [];
        $enricher = $this->createStub(ItemEnricherInterface::class);
        $enricher->method('enrich')->willReturnCallback(
            static function (array $items) use (&$chunks): void {
                $chunks[] = array_map(static fn (SitemapItemInterface $item) => $item->getUrl(), $items);
            }
        );

        $this->generator(
            ['page' => $this->provider(ItemProviderInterface::TYPE_PAGES, ['1', '2', '3', '4', '5'])],
            null,
            [$enricher],
            [],
            null,
            2
        )->generate($this->sitemap());

        $this->assertSame([['1', '2'], ['3', '4'], ['5']], $chunks);
    }

    public function testEveryFileDeclaresTheNamespacesOfTheRenderers(): void
    {
        $images = $this->renderer('', ['image' => 'http://www.google.com/schemas/sitemap-image/1.1']);
        $xhtml  = $this->renderer('', ['xhtml' => 'http://www.w3.org/1999/xhtml']);

        $this->generator([], [$images, $xhtml])->generate($this->sitemap());

        $this->assertSame(
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . PHP_EOL,
            $this->writerArguments['urlsetOpen'] ?? null
        );
    }

    public function testTheSitemapIsSavedWithItsGenerationTimeOnceTheFilesAreWritten(): void
    {
        $order   = [];
        $sitemap = $this->createMock(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            function (string $method, array $arguments) use (&$order) {
                if ($method === 'setSitemapTime') {
                    $order[] = 'time:' . $arguments[0];
                    $this->assertContains('finish', $this->written, 'The time was set before the files were written.');
                }
                return $method === 'getStoreId' ? 1 : null;
            }
        );
        $sitemap->expects($this->once())->method('save')->willReturnCallback(
            static function () use (&$order, $sitemap) {
                $order[] = 'save';
                return $sitemap;
            }
        );

        $this->generator([])->generate($sitemap);

        $this->assertSame(['time:2026-09-23 12:00:00', 'save'], $order);
    }

    /**
     * @param array<string,object> $providers
     * @param RowRendererInterface[]|null $renderers Null: one renderer writing the item's URL
     * @param ItemEnricherInterface[] $enrichers
     * @param ItemFilterInterface[] $filters
     * @param LoggerInterface|null $logger
     * @param int $chunkSize
     * @return Generator
     */
    private function generator(
        array $providers,
        ?array $renderers = null,
        array $enrichers = [],
        array $filters = [],
        ?LoggerInterface $logger = null,
        int $chunkSize = 1000
    ): Generator {
        $composite = $this->createStub(Composite::class);
        $composite->method('getProviders')->willReturn($providers);

        $itemFactory = $this->createStub(SitemapItemFactory::class);
        $itemFactory->method('create')->willReturnCallback(
            static fn (array $data) => new SitemapItem($data['url'], $data['priority'], $data['changeFrequency'])
        );
        $adapterFactory = $this->createStub(CoreProviderAdapterFactory::class);
        $adapterFactory->method('create')->willReturnCallback(
            static fn (array $arguments) => new CoreProviderAdapter($arguments['provider'], $itemFactory)
        );

        $writer = $this->createStub(Writer::class);
        $writer->method('startType')->willReturnCallback(function (string $type): void {
            $this->written[] = 'type:' . $type;
        });
        $writer->method('writeRow')->willReturnCallback(function (string $row): void {
            // Every row is a <url> element; record what is inside it.
            $this->assertMatchesRegularExpression('#^<url>.*</url>$#s', $row);
            $this->written[] = 'row:' . substr($row, 5, -6);
        });
        $writer->method('finish')->willReturnCallback(function (): array {
            $this->written[] = 'finish';
            return [];
        });
        $writerFactory = $this->createStub(WriterFactory::class);
        $writerFactory->method('create')->willReturnCallback(function (array $arguments) use ($writer): Writer {
            $this->writerArguments = $arguments;
            return $writer;
        });

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-23 12:00:00');

        return new Generator(
            $composite,
            $adapterFactory,
            $writerFactory,
            $dateTime,
            $logger ?? $this->createStub(LoggerInterface::class),
            $renderers ?? [$this->urlRenderer()],
            $enrichers,
            $filters,
            $chunkSize
        );
    }

    /**
     * A provider of the given type listing the given URLs.
     *
     * @param string $type
     * @param string[] $urls
     * @return ItemProviderInterface
     */
    private function provider(string $type, array $urls): ItemProviderInterface
    {
        $provider = $this->createStub(ItemProviderInterface::class);
        $provider->method('getType')->willReturn($type);
        $provider->method('iterateItems')->willReturnCallback(
            static function () use ($urls): \Generator {
                foreach ($urls as $url) {
                    yield new SitemapItem($url, '0.5', 'daily');
                }
            }
        );

        return $provider;
    }

    /**
     * A renderer writing just the item's URL, so the rows can be told apart.
     *
     * @return RowRendererInterface
     */
    private function urlRenderer(): RowRendererInterface
    {
        $renderer = $this->createStub(RowRendererInterface::class);
        $renderer->method('getNamespaces')->willReturn([]);
        $renderer->method('render')->willReturnCallback(
            static fn (SitemapItemInterface $item): string => (string) $item->getUrl()
        );

        return $renderer;
    }

    /**
     * A renderer returning fixed output.
     *
     * @param string $output
     * @param array<string,string> $namespaces
     * @return RowRendererInterface
     */
    private function renderer(string $output, array $namespaces = []): RowRendererInterface
    {
        $renderer = $this->createStub(RowRendererInterface::class);
        $renderer->method('getNamespaces')->willReturn($namespaces);
        $renderer->method('render')->willReturn($output);

        return $renderer;
    }

    /**
     * A sitemap for store view 1.
     *
     * @return Sitemap
     */
    private function sitemap(): Sitemap
    {
        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => $method === 'getStoreId' ? 1 : null
        );

        return $sitemap;
    }
}
