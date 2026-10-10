<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use Magento\Framework\App\RequestInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\DirectiveComposer;
use MageOS\Seo\Model\RobotsMeta\Provider\CatalogPaginationRobotsProvider;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Without a category provider, the category has no directive.
     *
     * @param RequestInterface|null $request
     * @param Config|null $config
     * @param CategoryRobotsProvider|null $category
     * @return CatalogPaginationRobotsProvider
     */
    private function provider(
        ?RequestInterface $request = null,
        ?Config $config = null,
        ?CategoryRobotsProvider $category = null
    ): CatalogPaginationRobotsProvider {
        if ($category === null) {
            $category = $this->createStub(CategoryRobotsProvider::class);
            $category->method('getRobots')->willReturn(null);
        }

        return new CatalogPaginationRobotsProvider(
            $request ?? $this->request,
            $config ?? $this->config,
            $category,
            new DirectiveComposer()
        );
    }

    /**
     * Issue #34: on page 2 and later, every restriction of the category's own directive is kept,
     * and the paginated value can add more; it never relaxes one.
     *
     * @dataProvider laterPages
     * @param string $category The category's directive: its own, inherited or the default
     * @param string $paginated The Paginated Pages Robots setting
     * @param string $expected
     * @return void
     */
    #[DataProvider('laterPages')]
    public function testOnLaterPagesTheCategorysRestrictionsAreKept(
        string $category,
        string $paginated,
        string $expected
    ): void {
        $this->assertSame($expected, $this->secondPage($paginated, $category)->getRobots(1));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function laterPages(): array
    {
        return [
            'a NOINDEX category stays NOINDEX'     => ['NOINDEX,FOLLOW', 'INDEX,FOLLOW', 'NOINDEX,FOLLOW'],
            'NOFOLLOW is kept and NOINDEX added'   => ['INDEX,NOFOLLOW', 'NOINDEX,FOLLOW', 'NOINDEX,NOFOLLOW'],
            'noarchive is kept'                    => [
                'INDEX,FOLLOW,noarchive',
                'NOINDEX,FOLLOW',
                'NOINDEX,FOLLOW,noarchive',
            ],
            'the paginated value adds its NOINDEX' => ['INDEX,FOLLOW', 'NOINDEX,FOLLOW', 'NOINDEX,FOLLOW'],
        ];
    }

    /**
     * @return void
     */
    public function testWithNoCategoryDirectiveThePaginatedValueApplies(): void
    {
        $this->assertSame('INDEX,FOLLOW', $this->secondPage('INDEX,FOLLOW', null)->getRobots(1));
    }

    /**
     * Page 1 is the category's own page: its directive is not asked for here.
     *
     * @return void
     */
    public function testOnPageOneTheCategoryIsNotAsked(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPaginatedRobotsEnabled')->willReturn(true);
        $config->method('getRobotsPaginated')->willReturn('INDEX,FOLLOW');
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn('1');
        $category = $this->createMock(CategoryRobotsProvider::class);
        $category->expects($this->never())->method('getRobots');

        $this->assertNull($this->provider($request, $config, $category)->getRobots(1));
    }

    /**
     * The provider on page 2 of a category with the given directive, for store view 1.
     *
     * @param string $paginated
     * @param string|null $category
     * @return CatalogPaginationRobotsProvider
     */
    private function secondPage(string $paginated, ?string $category): CatalogPaginationRobotsProvider
    {
        $config = $this->createStub(Config::class);
        $config->method('isPaginatedRobotsEnabled')->willReturn(true);
        $config->method('getRobotsPaginated')->willReturn($paginated);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key): ?string => $key === 'p' ? '2' : null);
        $categoryProvider = $this->createMock(CategoryRobotsProvider::class);
        $categoryProvider->expects($this->once())->method('getRobots')->with(1)->willReturn($category);

        return $this->provider($request, $config, $categoryProvider);
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
