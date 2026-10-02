<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use MageOS\Seo\Api\RobotsMetaProviderInterface;
use MageOS\Seo\Model\Pool\HandleMatcher;
use MageOS\Seo\Model\RobotsMeta\Resolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ResolverTest extends TestCase
{
    /**
     * @var Layout&Stub
     */
    private Layout&Stub $layout;

    /**
     * @var ProcessorInterface&Stub
     */
    private ProcessorInterface&Stub $layoutUpdate;

    protected function setUp(): void
    {
        $this->layout       = $this->createStub(Layout::class);
        $this->layoutUpdate = $this->createStub(ProcessorInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->layoutUpdate);
    }

    /**
     * @param string[] $handles
     */
    private function makeProvider(
        array $handles,
        ?string $robots,
        int $sortOrder = 100
    ): RobotsMetaProviderInterface&Stub {
        $provider = $this->createStub(RobotsMetaProviderInterface::class);
        $provider->method('getHandles')->willReturn($handles);
        $provider->method('getRobots')->willReturn($robots);
        $provider->method('getSortOrder')->willReturn($sortOrder);
        return $provider;
    }

    public function testReturnsNullWithNoProviders(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $resolver = new Resolver($this->layout, new HandleMatcher(), []);
        $this->assertNull($resolver->resolve(1));
    }

    public function testReturnsNullWhenProviderHandleDoesNotMatch(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(['catalog_product_view'], 'INDEX,FOLLOW');
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$provider]);
        $this->assertNull($resolver->resolve(1));
    }

    public function testReturnsValueFromMatchingProvider(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(['catalog_product_view'], 'NOINDEX,FOLLOW');
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$provider]);
        $this->assertSame('NOINDEX,FOLLOW', $resolver->resolve(1));
    }

    public function testWildcardProviderMatchesAnyPage(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(['*'], 'INDEX,FOLLOW');
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$provider]);
        $this->assertSame('INDEX,FOLLOW', $resolver->resolve(1));
    }

    public function testHighestSortOrderWins(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $low  = $this->makeProvider(['*'], 'INDEX,FOLLOW', 100);
        $high = $this->makeProvider(['catalog_product_view'], 'NOINDEX,NOFOLLOW', 200);
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$low, $high]);
        $this->assertSame('NOINDEX,NOFOLLOW', $resolver->resolve(1));
    }

    public function testProviderReturningNullIsSkipped(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $null    = $this->makeProvider(['*'], null, 200);
        $nonNull = $this->makeProvider(['*'], 'INDEX,FOLLOW', 100);
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$null, $nonNull]);
        $this->assertSame('INDEX,FOLLOW', $resolver->resolve(1));
    }

    public function testProviderReturningEmptyStringIsSkipped(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $empty   = $this->makeProvider(['*'], '', 200);
        $nonEmpty = $this->makeProvider(['*'], 'INDEX,FOLLOW', 100);
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$empty, $nonEmpty]);
        $this->assertSame('INDEX,FOLLOW', $resolver->resolve(1));
    }

    public function testAllProvidersEmptyReturnsNull(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $p1 = $this->makeProvider(['*'], null, 200);
        $p2 = $this->makeProvider(['*'], '', 100);
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$p1, $p2]);
        $this->assertNull($resolver->resolve(1));
    }

    public function testNonProviderObjectsAreSkipped(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $resolver = new Resolver($this->layout, new HandleMatcher(), [new \stdClass(), 'not-a-provider']);
        $this->assertNull($resolver->resolve(1));
    }

    public function testStoreIdIsPassedToProvider(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->createMock(RobotsMetaProviderInterface::class);
        $provider->method('getHandles')->willReturn(['*']);
        $provider->method('getSortOrder')->willReturn(100);
        $provider->expects($this->once())
            ->method('getRobots')
            ->with(7)
            ->willReturn('INDEX,FOLLOW');
        $resolver = new Resolver($this->layout, new HandleMatcher(), [$provider]);
        $this->assertSame('INDEX,FOLLOW', $resolver->resolve(7));
    }
}
