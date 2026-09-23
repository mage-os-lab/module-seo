<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use Magento\Cms\Api\Data\PageInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CmsPageRobotsProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
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
     * @var CmsPageResolver&MockObject
     */
    private CmsPageResolver&MockObject $cmsPageResolver;

    /**
     * @var Config&MockObject
     */
    private Config&MockObject $config;

    /**
     * The row the page's own configuration returns.
     *
     * @var mixed[]
     */
    private array $pageConfig = [];

    /**
     * @var CmsPageRobotsProvider
     */
    private CmsPageRobotsProvider $provider;

    protected function setUp(): void
    {
        $this->cmsPageResolver = $this->createMock(CmsPageResolver::class);
        $this->config          = $this->createMock(Config::class);
        $this->pageConfig      = [];

        $configRepository = $this->createStub(CmsPageConfigRepository::class);
        $configRepository->method('getForPage')->willReturnCallback(fn (): array => $this->pageConfig);

        $this->provider = new CmsPageRobotsProvider(
            $this->cmsPageResolver,
            $this->config,
            $configRepository
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
        $this->cmsPageResolver->method('resolve')->willReturn($this->createMock(PageInterface::class));
        $this->config->method('getRobotsCmsDefault')->with(1)->willReturn('INDEX,FOLLOW');
        $this->assertSame('INDEX,FOLLOW', $this->provider->getRobots(1));
    }

    public function testReturnsNullWhenConfigDefaultEmpty(): void
    {
        $this->cmsPageResolver->method('resolve')->willReturn($this->createMock(PageInterface::class));
        $this->config->method('getRobotsCmsDefault')->with(1)->willReturn('');
        $this->assertNull($this->provider->getRobots(1));
    }
}
