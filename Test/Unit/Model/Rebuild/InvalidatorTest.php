<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\Invalidator;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Sitemap\InvalidationPolicy;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\TestCase;

class InvalidatorTest extends TestCase
{
    /**
     * @return void
     */
    public function testAHandlersGroupIsQueuedWhileItsHandlerCanBuildIt(): void
    {
        $requested   = [];
        $invalidator = $this->invalidator($requested, handlerEnabled: ['one']);

        $invalidator->invalidate('one');
        $invalidator->invalidate('two');

        $this->assertSame(['one'], $requested);
    }

    /**
     * @return void
     */
    public function testAGroupNoHandlerOwnsIsRefusedWhereItIsAskedFor(): void
    {
        $requested = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No rebuild handler owns the group "three". Registered groups: one, two.');
        $this->invalidator($requested)->invalidate('three');
    }

    /**
     * @return void
     */
    public function testASitemapTypeIsQueuedAsItsGroupWhenASitemapWouldBeRebuilt(): void
    {
        $requested   = [];
        $invalidator = $this->invalidator($requested, sitemapsEnabled: true);

        $invalidator->invalidateSitemap('products');
        $invalidator->invalidateSitemap('*');

        $this->assertSame(['sitemap-products', 'sitemap-*'], $requested);

        $requested = [];
        $this->invalidator($requested, sitemapsEnabled: false)->invalidateSitemap('products');
        $this->assertSame([], $requested);
    }

    /**
     * @return void
     */
    public function testTheFirstBuildIsQueuedAsItsOwnGroupWhenASitemapWouldBeRebuilt(): void
    {
        $requested = [];
        $this->invalidator($requested, sitemapsEnabled: true)->invalidateMissingSitemaps();
        $this->assertSame([RebuildGroup::MISSING], $requested);

        $requested = [];
        $this->invalidator($requested, sitemapsEnabled: false)->invalidateMissingSitemaps();
        $this->assertSame([], $requested);
    }

    /**
     * @return void
     */
    public function testInvalidationTouchesNeitherFilesNorCaches(): void
    {
        // Served files and cached responses are left alone: the invalidator depends on nothing
        // that could delete or purge, so a save can no longer take a feed offline.
        $constructor = (new \ReflectionClass(Invalidator::class))->getConstructor();
        $this->assertNotNull($constructor);

        $dependencies = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters()
        );
        $this->assertSame(
            [RegenerationRequester::class, InvalidationPolicy::class, RebuildGroup::class, HandlerPool::class],
            $dependencies
        );
    }

    /**
     * An invalidator over a handler owning `one` and `two`, recording requests.
     *
     * @param string[] $requested Receives the requested groups
     * @param bool $sitemapsEnabled Whether a sitemap would be rebuilt
     * @param string[] $handlerEnabled The handler's groups it reports enabled
     * @return Invalidator
     */
    private function invalidator(
        array &$requested,
        bool $sitemapsEnabled = true,
        array $handlerEnabled = []
    ): Invalidator {
        $requester = $this->createStub(RegenerationRequester::class);
        $requester->method('request')->willReturnCallback(
            static function (string $group) use (&$requested): void {
                $requested[] = $group;
            }
        );
        $policy = $this->createStub(InvalidationPolicy::class);
        $policy->method('isEnabled')->willReturn($sitemapsEnabled);

        $handler = $this->createStub(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn(['one', 'two']);
        $handler->method('isEnabled')->willReturnCallback(
            static fn (string $group): bool => \in_array($group, $handlerEnabled, true)
        );

        return new Invalidator($requester, $policy, new RebuildGroup(), new HandlerPool([$handler]));
    }
}
