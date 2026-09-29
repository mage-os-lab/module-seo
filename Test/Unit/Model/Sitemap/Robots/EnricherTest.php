<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap\Robots;

use Magento\Cms\Api\Data\PageInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Category\PathResolver;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use MageOS\Seo\Model\RobotsMeta\Provider\CmsPageRobotsProvider;
use MageOS\Seo\Model\RobotsMeta\Provider\ProductRobotsProvider;
use MageOS\Seo\Model\Sitemap\Robots\Directive;
use MageOS\Seo\Model\Sitemap\Robots\Enricher;
use MageOS\Seo\Model\Sitemap\SitemapItem;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * How directives are asked for a chunk and filed. That they match what the pages carry, on a real
 * install, is covered by Test/Integration/Model/Sitemap/RobotsTest.
 */
class EnricherTest extends TestCase
{
    private const CORE_DEFAULT = 'CORE,DEFAULT';

    /**
     * @var Config&Stub
     */
    private Config&Stub $config;

    /**
     * @var ProductRobotsProvider&Stub
     */
    private ProductRobotsProvider&Stub $productRobots;

    /**
     * @var CategoryRobotsProvider&Stub
     */
    private CategoryRobotsProvider&Stub $categoryRobots;

    /**
     * @var CmsPageRobotsProvider&Stub
     */
    private CmsPageRobotsProvider&Stub $cmsPageRobots;

    /**
     * @var PathResolver&Stub
     */
    private PathResolver&Stub $pathResolver;

    /**
     * @var CmsPageResolver&Stub
     */
    private CmsPageResolver&Stub $cmsPageResolver;

    protected function setUp(): void
    {
        $this->config          = $this->createStub(Config::class);
        $this->productRobots   = $this->createStub(ProductRobotsProvider::class);
        $this->categoryRobots  = $this->createStub(CategoryRobotsProvider::class);
        $this->cmsPageRobots   = $this->createStub(CmsPageRobotsProvider::class);
        $this->pathResolver    = $this->createStub(PathResolver::class);
        $this->cmsPageResolver = $this->createStub(CmsPageResolver::class);

        $this->config->method('isSitemapNoindexExcluded')->willReturnMap([[1, true]]);
        $this->config->method('getRobotsCoreDefault')->willReturnMap([[1, self::CORE_DEFAULT]]);
    }

    public function testProductsAreAskedForOnceAChunk(): void
    {
        $productRobots = $this->createMock(ProductRobotsProvider::class);
        $productRobots->expects($this->once())
            ->method('forProducts')
            ->with([5, 6], 1)
            ->willReturn([5 => 'NOINDEX,FOLLOW', 6 => 'INDEX,FOLLOW']);
        $this->productRobots = $productRobots;

        $items = $this->items(SitemapItemInterface::ENTITY_PRODUCT, [5, 6]);
        $this->enricher()->enrich($items, 1);

        $this->assertSame(['NOINDEX,FOLLOW', 'INDEX,FOLLOW'], $this->directivesOf($items));
    }

    public function testCmsPagesAreAskedForOnceAChunk(): void
    {
        $cmsPageRobots = $this->createMock(CmsPageRobotsProvider::class);
        $cmsPageRobots->expects($this->once())
            ->method('forPages')
            ->with([3, 4], 1)
            ->willReturn([3 => 'NOINDEX', 4 => 'INDEX']);
        $this->cmsPageRobots = $cmsPageRobots;

        $items = $this->items(SitemapItemInterface::ENTITY_CMS_PAGE, [3, 4]);
        $this->enricher()->enrich($items, 1);

        $this->assertSame(['NOINDEX', 'INDEX'], $this->directivesOf($items));
    }

    public function testEachCategoryIsAskedWithItsPath(): void
    {
        $pathResolver = $this->createMock(PathResolver::class);
        $pathResolver->expects($this->once())
            ->method('forCategoryIds')
            ->with([9, 12])
            ->willReturn([9 => ['1', '2', '9'], 12 => ['1', '2', '9', '12']]);
        $this->pathResolver = $pathResolver;
        $this->categoryRobots->method('forCategory')->willReturnMap([
            [9, ['1', '2', '9'], 1, 'NOINDEX,FOLLOW'],
            [12, ['1', '2', '9', '12'], 1, 'NOINDEX,FOLLOW'],
        ]);

        $items = $this->items(SitemapItemInterface::ENTITY_CATEGORY, [9, 12]);
        $this->enricher()->enrich($items, 1);

        $this->assertSame(['NOINDEX,FOLLOW', 'NOINDEX,FOLLOW'], $this->directivesOf($items));
    }

    public function testTheHomePageGetsItsCmsPagesDirective(): void
    {
        $page = $this->createStub(PageInterface::class);
        $page->method('getId')->willReturn(2);
        $this->cmsPageResolver->method('resolveHome')->willReturnMap([[1, $page]]);
        $this->cmsPageRobots->method('forPages')->willReturnMap([[[2], 1, [2 => 'NOINDEX,NOFOLLOW']]]);

        $items = [$this->item(SitemapItemInterface::ENTITY_STORE, null)];
        $this->enricher()->enrich($items, 1);

        $this->assertSame(['NOINDEX,NOFOLLOW'], $this->directivesOf($items));
    }

    /**
     * Where this module has nothing to say, the page keeps core's default — and so does its entry.
     */
    public function testCoresDefaultAppliesWhereThisModuleHasNoDirective(): void
    {
        $this->productRobots->method('forProducts')->willReturn([5 => null]);
        $this->cmsPageResolver->method('resolveHome')->willReturn(null);
        $this->pathResolver->method('forCategoryIds')->willReturn([]);

        $items = [
            $this->item(SitemapItemInterface::ENTITY_PRODUCT, 5),
            $this->item(SitemapItemInterface::ENTITY_STORE, null),
            $this->item(SitemapItemInterface::ENTITY_CATEGORY, 99),
            $this->item('blog-post', 7),
            $this->item(null, null),
        ];
        $this->enricher()->enrich($items, 1);

        $this->assertSame(array_fill(0, 5, self::CORE_DEFAULT), $this->directivesOf($items));
    }

    public function testNothingIsReadOrFiledWhenTheSettingIsOff(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSitemapNoindexExcluded')->willReturn(false);
        $this->config  = $config;
        $productRobots = $this->createMock(ProductRobotsProvider::class);
        $productRobots->expects($this->never())->method('forProducts');
        $this->productRobots = $productRobots;

        $item = $this->item(SitemapItemInterface::ENTITY_PRODUCT, 5);
        $this->enricher()->enrich([$item], 1);

        $this->assertSame([], $item->getDataBag());
    }

    /**
     * @return Enricher
     */
    private function enricher(): Enricher
    {
        return new Enricher(
            $this->config,
            $this->productRobots,
            $this->categoryRobots,
            $this->cmsPageRobots,
            $this->pathResolver,
            $this->cmsPageResolver
        );
    }

    /**
     * @param string|null $entityType
     * @param int|null $entityId
     * @return SitemapItem
     */
    private function item(?string $entityType, ?int $entityId): SitemapItem
    {
        return new SitemapItem('page.html', '0.5', 'daily', null, null, $entityType, $entityId);
    }

    /**
     * One item per entity ID, all of one type.
     *
     * @param string $entityType
     * @param int[] $entityIds
     * @return SitemapItem[]
     */
    private function items(string $entityType, array $entityIds): array
    {
        return array_map(fn (int $id): SitemapItem => $this->item($entityType, $id), $entityIds);
    }

    /**
     * Each item's filed directive, in order.
     *
     * @param SitemapItem[] $items
     * @return string[]
     */
    private function directivesOf(array $items): array
    {
        return array_map(
            function (SitemapItem $item): string {
                $directive = $item->getDataBag()[Enricher::BAG_KEY] ?? null;
                $this->assertInstanceOf(Directive::class, $directive);

                return $directive->getDirective();
            },
            $items
        );
    }
}
