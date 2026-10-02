<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\PathResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CategoryRobotsProviderTest extends TestCase
{
    /**
     * @var Layer&Stub
     */
    private Layer&Stub $layer;

    /**
     * @var CategoryConfigRepository&Stub
     */
    private CategoryConfigRepository&Stub $configRepository;

    /**
     * @var Config&Stub
     */
    private Config&Stub $config;

    /**
     * @var PathResolver&Stub
     */
    private PathResolver&Stub $pathResolver;

    /**
     * @var CategoryRobotsProvider
     */
    private CategoryRobotsProvider $provider;

    protected function setUp(): void
    {
        $this->layer            = $this->createStub(Layer::class);
        $this->configRepository = $this->createStub(CategoryConfigRepository::class);
        $this->config           = $this->createStub(Config::class);
        $this->pathResolver     = $this->createStub(PathResolver::class);
        $this->provider         = $this->provider();
    }

    /**
     * The provider under test, over the given doubles or this test's stubs.
     *
     * Without a layer resolver of the test's own, it gets one that returns this test's layer.
     *
     * @param LayerResolver|null $layerResolver
     * @param CategoryConfigRepository|null $configRepository
     * @param Config|null $config
     * @return CategoryRobotsProvider
     */
    private function provider(
        ?LayerResolver $layerResolver = null,
        ?CategoryConfigRepository $configRepository = null,
        ?Config $config = null
    ): CategoryRobotsProvider {
        if ($layerResolver === null) {
            $layerResolver = $this->createStub(LayerResolver::class);
            $layerResolver->method('get')->willReturn($this->layer);
        }

        return new CategoryRobotsProvider(
            $layerResolver,
            $configRepository ?? $this->configRepository,
            $config ?? $this->config,
            $this->pathResolver
        );
    }

    /**
     * The layer's current category, and the path PathResolver gives for it.
     *
     * @param int $id
     * @param string[] $path
     * @return void
     */
    private function withCategory(int $id, array $path = []): void
    {
        $category = $this->createStub(CategoryInterface::class);
        $category->method('getId')->willReturn($id);
        $this->layer->method('getCurrentCategory')->willReturn($category);
        $this->pathResolver->method('forCategory')->willReturnMap([[$category, $path]]);
    }

    public function testHandlesCategoryView(): void
    {
        $this->assertSame(['catalog_category_view'], $this->provider->getHandles());
    }

    public function testSortOrderIsHundred(): void
    {
        $this->assertSame(100, $this->provider->getSortOrder());
    }

    public function testReturnsNullWhenNoCurrentCategory(): void
    {
        $this->layer->method('getCurrentCategory')->willReturn(null);
        $this->assertNull($this->provider->getRobots(1));
    }

    public function testReturnsPerCategoryOverride(): void
    {
        $this->withCategory(9);
        $configRepository = $this->createMock(CategoryConfigRepository::class);
        $configRepository->method('getForCategory')->with(9, [], 1)
            ->willReturn(['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->assertSame('NOINDEX,FOLLOW', $this->provider(configRepository: $configRepository)->getRobots(1));
    }

    public function testFallsBackToConfigDefault(): void
    {
        $this->withCategory(9);
        $configRepository = $this->createMock(CategoryConfigRepository::class);
        $configRepository->method('getForCategory')->with(9, [], 1)
            ->willReturn(['robots_meta' => null]);
        $config = $this->createMock(Config::class);
        $config->method('getRobotsCategoryDefault')->with(1)->willReturn('INDEX,FOLLOW');
        $provider = $this->provider(configRepository: $configRepository, config: $config);
        $this->assertSame('INDEX,FOLLOW', $provider->getRobots(1));
    }

    public function testReturnsNullWhenOverrideAndDefaultBothEmpty(): void
    {
        $this->withCategory(9);
        $configRepository = $this->createMock(CategoryConfigRepository::class);
        $configRepository->method('getForCategory')->with(9, [], 1)
            ->willReturn([]);
        $config = $this->createMock(Config::class);
        $config->method('getRobotsCategoryDefault')->with(1)->willReturn('');
        $provider = $this->provider(configRepository: $configRepository, config: $config);
        $this->assertNull($provider->getRobots(1));
    }

    public function testTheAncestorPathIsPassedSoSettingsCanBeInherited(): void
    {
        // Without the path the repository reads this category's own row and nothing else, and a
        // value set on an ancestor — which the admin form shows this category inheriting — never
        // reaches the page.
        $this->withCategory(9, ['1', '2', '5', '9']);
        $configRepository = $this->createMock(CategoryConfigRepository::class);
        $configRepository->expects($this->once())
            ->method('getForCategory')
            ->with(9, ['1', '2', '5', '9'], 1)
            ->willReturn(['robots_meta' => 'NOINDEX,FOLLOW']);

        $this->assertSame('NOINDEX,FOLLOW', $this->provider(configRepository: $configRepository)->getRobots(1));
    }

    /**
     * The sitemap asks for a category it has only by ID and path, with no layer.
     */
    public function testForCategoryAnswersWithoutTheLayer(): void
    {
        $layerResolver = $this->createMock(LayerResolver::class);
        $layerResolver->expects($this->never())->method('get');
        $configRepository = $this->createMock(CategoryConfigRepository::class);
        $configRepository->method('getForCategory')->with(9, ['1', '2', '9'], 3)
            ->willReturn(['robots_meta' => 'NOINDEX,FOLLOW']);
        $provider = $this->provider($layerResolver, $configRepository);

        $this->assertSame('NOINDEX,FOLLOW', $provider->forCategory(9, ['1', '2', '9'], 3));
    }

    public function testForCategoryFallsBackToTheDefaultAndThenToNothing(): void
    {
        $this->configRepository->method('getForCategory')->willReturn(['robots_meta' => '']);
        $this->config->method('getRobotsCategoryDefault')->willReturnMap([[3, 'NOINDEX'], [4, '']]);

        $this->assertSame('NOINDEX', $this->provider->forCategory(9, [], 3));
        $this->assertNull($this->provider->forCategory(9, [], 4));
    }
}
