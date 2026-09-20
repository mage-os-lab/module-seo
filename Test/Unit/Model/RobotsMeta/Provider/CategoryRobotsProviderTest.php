<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\PathResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryRobotsProviderTest extends TestCase
{
    /**
     * @var LayerResolver&MockObject
     */
    private LayerResolver&MockObject $layerResolver;

    /**
     * @var Layer&MockObject
     */
    private Layer&MockObject $layer;

    /**
     * @var CategoryConfigRepository&MockObject
     */
    private CategoryConfigRepository&MockObject $configRepository;

    /**
     * @var Config&MockObject
     */
    private Config&MockObject $config;

    /**
     * @var CategoryRobotsProvider
     */
    private CategoryRobotsProvider $provider;

    protected function setUp(): void
    {
        $this->layerResolver    = $this->createMock(LayerResolver::class);
        $this->layer            = $this->createMock(Layer::class);
        $this->configRepository = $this->createMock(CategoryConfigRepository::class);
        $this->config           = $this->createMock(Config::class);
        $this->layerResolver->method('get')->willReturn($this->layer);
        $this->provider = new CategoryRobotsProvider(
            $this->layerResolver,
            $this->configRepository,
            $this->config,
            new PathResolver($this->createStub(CategoryRepositoryInterface::class))
        );
    }

    /**
     * @param int $id
     * @param string $path
     * @return void
     */
    private function withCategory(int $id, string $path = ''): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn($id);
        $category->method('getPath')->willReturn($path);
        $this->layer->method('getCurrentCategory')->willReturn($category);
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
        $this->configRepository->method('getForCategory')->with(9, [], 1)
            ->willReturn(['robots_meta' => 'NOINDEX,FOLLOW']);
        $this->assertSame('NOINDEX,FOLLOW', $this->provider->getRobots(1));
    }

    public function testFallsBackToConfigDefault(): void
    {
        $this->withCategory(9);
        $this->configRepository->method('getForCategory')->with(9, [], 1)
            ->willReturn(['robots_meta' => null]);
        $this->config->method('getRobotsCategoryDefault')->with(1)->willReturn('INDEX,FOLLOW');
        $this->assertSame('INDEX,FOLLOW', $this->provider->getRobots(1));
    }

    public function testReturnsNullWhenOverrideAndDefaultBothEmpty(): void
    {
        $this->withCategory(9);
        $this->configRepository->method('getForCategory')->with(9, [], 1)
            ->willReturn([]);
        $this->config->method('getRobotsCategoryDefault')->with(1)->willReturn('');
        $this->assertNull($this->provider->getRobots(1));
    }

    public function testTheAncestorPathIsPassedSoSettingsCanBeInherited(): void
    {
        // Without the path the repository reads this category's own row and nothing else, and a
        // value set on an ancestor — which the admin form shows this category inheriting — never
        // reaches the page.
        $this->withCategory(9, '1/2/5/9');
        $this->configRepository->expects($this->once())
            ->method('getForCategory')
            ->with(9, ['1', '2', '5', '9'], 1)
            ->willReturn(['robots_meta' => 'NOINDEX,FOLLOW']);

        $this->assertSame('NOINDEX,FOLLOW', $this->provider->getRobots(1));
    }
}
