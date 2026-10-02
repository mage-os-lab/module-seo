<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use Magento\Cms\Api\Data\PageInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CmsPageRobotsProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The page-config repository is built on a generated collection factory, so this test needs an
 * installation to have generated it. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class CmsPageRobotsProviderTest extends TestCase
{
    /**
     * @var CmsPageResolver&Stub
     */
    private CmsPageResolver&Stub $cmsPageResolver;

    /**
     * @var Config&Stub
     */
    private Config&Stub $config;

    /**
     * @var CmsPageConfigRepository&Stub
     */
    private CmsPageConfigRepository&Stub $configRepository;

    /**
     * The row the page's own configuration returns.
     *
     * @var mixed[]
     */
    private array $pageConfig = [];

    /**
     * The rows several pages' configuration returns, by page ID.
     *
     * @var array<int,mixed[]>
     */
    private array $pagesConfig = [];

    /**
     * @var CmsPageRobotsProvider
     */
    private CmsPageRobotsProvider $provider;

    protected function setUp(): void
    {
        $this->cmsPageResolver = $this->createStub(CmsPageResolver::class);
        $this->config          = $this->createStub(Config::class);
        $this->pageConfig      = [];

        $this->configRepository = $this->createStub(CmsPageConfigRepository::class);
        $this->configRepository->method('getForPage')->willReturnCallback(fn (): array => $this->pageConfig);
        $this->configRepository->method('getForPages')->willReturnCallback(fn (): array => $this->pagesConfig);

        $this->provider = $this->provider();
    }

    /**
     * The provider under test, over the given store configuration or this test's stub.
     *
     * @param Config|null $config
     * @return CmsPageRobotsProvider
     */
    private function provider(?Config $config = null): CmsPageRobotsProvider
    {
        return new CmsPageRobotsProvider(
            $this->cmsPageResolver,
            $config ?? $this->config,
            $this->configRepository
        );
    }

    public function testThePagesOwnOverrideWinsOverTheStoreDefault(): void
    {
        $this->cmsPageResolver->method('resolve')->willReturn($this->createStub(PageInterface::class));
        $this->pageConfig = ['robots_meta' => 'NOINDEX,FOLLOW'];
        $this->config->method('getRobotsCmsDefault')->willReturn('INDEX,FOLLOW');

        $this->assertSame('NOINDEX,FOLLOW', $this->provider->getRobots(1));
    }

    public function testAnEmptyOverrideFallsBackToTheStoreDefault(): void
    {
        // "Use Magento Default" stores nothing, and must not read as a directive of its own.
        $this->cmsPageResolver->method('resolve')->willReturn($this->createStub(PageInterface::class));
        $this->pageConfig = ['robots_meta' => ''];
        $this->config->method('getRobotsCmsDefault')->willReturn('INDEX,FOLLOW');

        $this->assertSame('INDEX,FOLLOW', $this->provider->getRobots(1));
    }

    public function testHandlesCmsPageView(): void
    {
        $this->assertSame(['cms_page_view'], $this->provider->getHandles());
    }

    public function testSortOrderIsHundred(): void
    {
        $this->assertSame(100, $this->provider->getSortOrder());
    }

    public function testReturnsNullWhenNotOnCmsPage(): void
    {
        $this->cmsPageResolver->method('resolve')->willReturn(null);
        $this->assertNull($this->provider->getRobots(1));
    }

    public function testReturnsConfigDefaultWhenOnCmsPage(): void
    {
        $this->cmsPageResolver->method('resolve')->willReturn($this->createStub(PageInterface::class));
        $config = $this->createMock(Config::class);
        $config->method('getRobotsCmsDefault')->with(1)->willReturn('INDEX,FOLLOW');
        $this->assertSame('INDEX,FOLLOW', $this->provider($config)->getRobots(1));
    }

    public function testReturnsNullWhenConfigDefaultEmpty(): void
    {
        $this->cmsPageResolver->method('resolve')->willReturn($this->createStub(PageInterface::class));
        $config = $this->createMock(Config::class);
        $config->method('getRobotsCmsDefault')->with(1)->willReturn('');
        $this->assertNull($this->provider($config)->getRobots(1));
    }

    /**
     * The sitemap's question, for a chunk of pages: each gets what its own page would.
     */
    public function testForPagesAppliesTheSameChainToEachPage(): void
    {
        $this->pagesConfig = [
            3 => ['robots_meta' => 'NOINDEX,FOLLOW'],
            4 => ['robots_meta' => ''],
            5 => [],
        ];
        $this->config->method('getRobotsCmsDefault')->willReturn('INDEX,FOLLOW');

        $this->assertSame(
            [3 => 'NOINDEX,FOLLOW', 4 => 'INDEX,FOLLOW', 5 => 'INDEX,FOLLOW'],
            $this->provider->forPages([3, 4, 5], 1)
        );
    }

    public function testForPagesGivesNullWhereNeitherOverrideNorDefaultSaysAnything(): void
    {
        $this->pagesConfig = [3 => []];
        $this->config->method('getRobotsCmsDefault')->willReturn('');

        $this->assertSame([3 => null], $this->provider->forPages([3], 1));
    }
}
