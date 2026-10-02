<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use Magento\Framework\App\RequestInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CatalogPaginationRobotsProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CatalogPaginationRobotsProviderTest extends TestCase
{
    /**
     * @var RequestInterface&Stub
     */
    private RequestInterface&Stub $request;

    /**
     * @var Config&Stub
     */
    private Config&Stub $config;

    /**
     * @var CatalogPaginationRobotsProvider
     */
    private CatalogPaginationRobotsProvider $provider;

    protected function setUp(): void
    {
        $this->request  = $this->createStub(RequestInterface::class);
        $this->config   = $this->createStub(Config::class);
        $this->provider = $this->provider();
    }

    /**
     * The provider under test, over the given doubles or this test's stubs.
     *
     * @param RequestInterface|null $request
     * @param Config|null $config
     * @return CatalogPaginationRobotsProvider
     */
    private function provider(
        ?RequestInterface $request = null,
        ?Config $config = null
    ): CatalogPaginationRobotsProvider {
        return new CatalogPaginationRobotsProvider($request ?? $this->request, $config ?? $this->config);
    }

    public function testHandlesCategoryView(): void
    {
        $this->assertSame(['catalog_category_view'], $this->provider->getHandles());
    }

    public function testSortOrderIsAboveCategoryDefault(): void
    {
        $this->assertSame(200, $this->provider->getSortOrder());
    }

    public function testReturnsNullWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPaginatedRobotsEnabled')->with(1)->willReturn(false);
        $this->assertNull($this->provider(config: $config)->getRobots(1));
    }

    public function testReturnsNullOnFirstPage(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPaginatedRobotsEnabled')->with(1)->willReturn(true);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('p')->willReturn('1');
        $this->assertNull($this->provider($request, $config)->getRobots(1));
    }

    public function testReturnsNullWhenNoPageParam(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPaginatedRobotsEnabled')->with(1)->willReturn(true);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('p')->willReturn(null);
        $this->assertNull($this->provider($request, $config)->getRobots(1));
    }

    public function testReturnsConfiguredRobotsOnSecondPage(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPaginatedRobotsEnabled')->with(1)->willReturn(true);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('p')->willReturn('2');
        $config->method('getRobotsPaginated')->with(1)->willReturn('NOINDEX,FOLLOW');
        $this->assertSame('NOINDEX,FOLLOW', $this->provider($request, $config)->getRobots(1));
    }

    public function testReturnsNullWhenEnabledButRobotsValueEmpty(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPaginatedRobotsEnabled')->with(1)->willReturn(true);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('p')->willReturn('3');
        $config->method('getRobotsPaginated')->with(1)->willReturn('');
        $this->assertNull($this->provider($request, $config)->getRobots(1));
    }
}
