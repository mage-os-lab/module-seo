<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\OverrideRepository;
use MageOS\Seo\Model\RobotsMeta\Provider\ProductRobotsProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ProductRobotsProviderTest extends TestCase
{
    /**
     * @var CurrentEntity&Stub
     */
    private CurrentEntity&Stub $currentEntity;

    /**
     * @var OverrideRepository&Stub
     */
    private OverrideRepository&Stub $overrideRepository;

    /**
     * @var Config&Stub
     */
    private Config&Stub $config;

    /**
     * @var ProductRobotsProvider
     */
    private ProductRobotsProvider $provider;

    protected function setUp(): void
    {
        $this->currentEntity      = $this->createStub(CurrentEntity::class);
        $this->overrideRepository = $this->createStub(OverrideRepository::class);
        $this->config             = $this->createStub(Config::class);
        $this->provider           = $this->provider();
    }

    /**
     * The provider under test, over the given doubles or this test's stubs.
     *
     * @param OverrideRepository|null $overrideRepository
     * @param Config|null $config
     * @return ProductRobotsProvider
     */
    private function provider(
        ?OverrideRepository $overrideRepository = null,
        ?Config $config = null
    ): ProductRobotsProvider {
        return new ProductRobotsProvider(
            $this->currentEntity,
            $overrideRepository ?? $this->overrideRepository,
            $config ?? $this->config
        );
    }

    private function withProduct(int $id): void
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        $this->currentEntity->method('getProduct')->willReturn($product);
    }

    public function testHandlesProductView(): void
    {
        $this->assertSame(['catalog_product_view'], $this->provider->getHandles());
    }

    public function testSortOrderIsHundred(): void
    {
        $this->assertSame(100, $this->provider->getSortOrder());
    }

    public function testReturnsNullWhenNoCurrentProduct(): void
    {
        $this->currentEntity->method('getProduct')->willReturn(null);
        $this->assertNull($this->provider->getRobots(1));
    }

    public function testReturnsPerProductOverride(): void
    {
        $this->withProduct(5);
        $overrideRepository = $this->createMock(OverrideRepository::class);
        $overrideRepository->method('getForProduct')->with(5, 1)
            ->willReturn(['override_fields' => [], 'robots_meta' => 'NOINDEX,FOLLOW']);
        $this->assertSame('NOINDEX,FOLLOW', $this->provider($overrideRepository)->getRobots(1));
    }

    public function testFallsBackToConfigDefaultWhenNoOverride(): void
    {
        $this->withProduct(5);
        $overrideRepository = $this->createMock(OverrideRepository::class);
        $overrideRepository->method('getForProduct')->with(5, 1)
            ->willReturn(['override_fields' => [], 'robots_meta' => null]);
        $config = $this->createMock(Config::class);
        $config->method('getRobotsProductDefault')->with(1)->willReturn('INDEX,FOLLOW');
        $this->assertSame('INDEX,FOLLOW', $this->provider($overrideRepository, $config)->getRobots(1));
    }

    public function testReturnsNullWhenOverrideAndDefaultBothEmpty(): void
    {
        $this->withProduct(5);
        $overrideRepository = $this->createMock(OverrideRepository::class);
        $overrideRepository->method('getForProduct')->with(5, 1)
            ->willReturn(['override_fields' => [], 'robots_meta' => null]);
        $config = $this->createMock(Config::class);
        $config->method('getRobotsProductDefault')->with(1)->willReturn('');
        $this->assertNull($this->provider($overrideRepository, $config)->getRobots(1));
    }

    /**
     * The sitemap's question, for a chunk of products: each gets what its own page would.
     */
    public function testForProductsAppliesTheSameChainToEachProduct(): void
    {
        $overrideRepository = $this->createMock(OverrideRepository::class);
        $overrideRepository->expects($this->once())
            ->method('getForProducts')
            ->with([5, 6, 7], 1)
            ->willReturn([
                5 => ['override_fields' => [], 'robots_meta' => 'NOINDEX,FOLLOW'],
                6 => ['override_fields' => [], 'robots_meta' => null],
                7 => ['override_fields' => [], 'robots_meta' => ''],
            ]);
        $config = $this->createMock(Config::class);
        $config->method('getRobotsProductDefault')->with(1)->willReturn('INDEX,FOLLOW');

        $this->assertSame(
            [5 => 'NOINDEX,FOLLOW', 6 => 'INDEX,FOLLOW', 7 => 'INDEX,FOLLOW'],
            $this->provider($overrideRepository, $config)->forProducts([5, 6, 7], 1)
        );
    }

    public function testForProductsGivesNullWhereNeitherOverrideNorDefaultSaysAnything(): void
    {
        $this->overrideRepository->method('getForProducts')
            ->willReturn([5 => ['override_fields' => [], 'robots_meta' => null]]);
        $this->config->method('getRobotsProductDefault')->willReturn('');

        $this->assertSame([5 => null], $this->provider->forProducts([5], 1));
    }
}
