<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\HreflangResolverInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\AlternateBuilder;
use MageOS\Seo\Model\Hreflang\ResolverPool;
use MageOS\Seo\Model\Hreflang\SelfReference;
use MageOS\Seo\Model\Pool\HandleMatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResolverPoolTest extends TestCase
{
    /**
     * @var ProcessorInterface&MockObject
     */
    private ProcessorInterface&MockObject $layoutUpdate;

    /**
     * @var Layout&MockObject
     */
    private Layout&MockObject $layout;

    /**
     * @var Config&MockObject
     */
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->layout       = $this->createMock(Layout::class);
        $this->layoutUpdate = $this->createMock(ProcessorInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->layoutUpdate);
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $this->config = $this->createMock(Config::class);
    }

    /**
     * @param string[] $handles
     * @param array<int, array{hreflang: string, url: string, store_id: int}> $links
     */
    private function makeResolver(array $handles, array $links): HreflangResolverInterface&MockObject
    {
        $resolver = $this->createMock(HreflangResolverInterface::class);
        $resolver->method('getHandles')->willReturn($handles);
        $resolver->method('getLinks')->willReturn($links);
        return $resolver;
    }

    /**
     * @return array{hreflang: string, url: string, store_id: int}
     */
    private function link(string $hreflang, string $url, int $storeId): array
    {
        return ['hreflang' => $hreflang, 'url' => $url, 'store_id' => $storeId];
    }

    /**
     * A store manager whose current store is the given store view, in website 1.
     *
     * @param int $storeId
     * @return StoreManagerInterface
     */
    private function storeManager(int $storeId = 1): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    /**
     * The real builder, on a store in website 1 and an event manager with no observers.
     *
     * @return AlternateBuilder
     */
    private function alternateBuilder(): AlternateBuilder
    {
        return new AlternateBuilder(
            $this->config,
            $this->storeManager(),
            $this->createStub(EventManagerInterface::class)
        );
    }

    /**
     * A pool with the given resolvers, serving the given store view.
     *
     * @param array<mixed> $resolvers
     * @param int $currentStoreId
     * @return ResolverPool
     */
    private function poolOf(array $resolvers, int $currentStoreId = 1): ResolverPool
    {
        return new ResolverPool(
            $this->layout,
            $this->config,
            new HandleMatcher(),
            $this->alternateBuilder(),
            $this->storeManager($currentStoreId),
            new SelfReference(),
            $resolvers
        );
    }

    /**
     * @param array<int, array{hreflang: string, url: string, store_id: int}> $links
     * @param int $currentStoreId
     */
    private function pool(array $links, int $currentStoreId = 1): ResolverPool
    {
        return $this->poolOf([$this->makeResolver(['catalog_product_view'], $links)], $currentStoreId);
    }

    public function testSingleStoreReturnsEmpty(): void
    {
        $pool = $this->pool([$this->link('en-GB', 'https://uk/p', 1)]);
        $this->assertSame([], $pool->getLinks());
    }

    public function testTwoStoresOutputBothRegionLinks(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $pool   = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('en-US', 'https://us/p', 2),
        ]);
        $result = $pool->getLinks();
        $hreflangs = array_column($result, 'hreflang');
        $this->assertContains('en-GB', $hreflangs);
        $this->assertContains('en-US', $hreflangs);
    }

    public function testLanguageOnlyTagsAddedForUniqueBaseLanguages(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $this->config->method('isHreflangLanguageOnlyEnabled')->willReturn(true);
        $pool   = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $hreflangs = array_column($pool->getLinks(), 'hreflang');
        $this->assertContains('en', $hreflangs);
        $this->assertContains('de', $hreflangs);
    }

    public function testLanguageOnlyTagNotAddedWhenTwoStoresShareLanguage(): void
    {
        $this->config->method('isHreflangLanguageOnlyEnabled')->willReturn(true);
        $pool   = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('en-US', 'https://us/p', 2),
        ]);
        $this->assertNotContains('en', array_column($pool->getLinks(), 'hreflang'));
    }

    public function testLanguageOnlyTagsSuppressedWhenDisabled(): void
    {
        $this->config->method('isHreflangLanguageOnlyEnabled')->willReturn(false);
        $pool   = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $this->assertNotContains('en', array_column($pool->getLinks(), 'hreflang'));
    }

    public function testXDefaultAddedForConfiguredStore(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $this->config->method('getHreflangXDefaultStoreId')->willReturn(2);
        $pool   = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $result = $pool->getLinks();
        $xDefault = array_values(array_filter($result, static fn ($l) => $l['hreflang'] === 'x-default'));
        $this->assertCount(1, $xDefault);
        $this->assertSame('https://de/p', $xDefault[0]['url']);
    }

    public function testXDefaultOmittedWhenStoreNotPresent(): void
    {
        $this->config->method('getHreflangXDefaultStoreId')->willReturn(99);
        $pool   = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $this->assertNotContains('x-default', array_column($pool->getLinks(), 'hreflang'));
    }

    public function testXDefaultOmittedWhenNotConfigured(): void
    {
        $pool = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $this->assertNotContains('x-default', array_column($pool->getLinks(), 'hreflang'));
    }

    public function testFirstMatchingResolverWithLinksWins(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $first  = $this->makeResolver(['catalog_product_view'], [
            $this->link('en-GB', 'https://uk/first', 1),
            $this->link('de-DE', 'https://de/first', 2),
        ]);
        $second = $this->makeResolver(['catalog_product_view'], [
            $this->link('en-GB', 'https://uk/second', 1),
            $this->link('de-DE', 'https://de/second', 2),
        ]);
        $pool = $this->poolOf([$first, $second]);
        $this->assertSame('https://uk/first', $pool->getLinks()[0]['url']);
    }

    public function testNonMatchingResolverIsSkipped(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $pool = $this->poolOf([
            $this->makeResolver(['cms_page_view'], [
                $this->link('en-GB', 'https://uk/p', 1),
                $this->link('de-DE', 'https://de/p', 2),
            ]),
        ]);
        $this->assertSame([], $pool->getLinks());
    }

    public function testNonResolverObjectsAreSkipped(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $pool = $this->poolOf([new \stdClass()]);
        $this->assertSame([], $pool->getLinks());
    }

    /**
     * An excluded store view has no link of its own; its pages must not declare the others.
     */
    public function testASetWithoutTheCurrentStoreViewIsNotDeclared(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $pool = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ], 3);
        $this->assertSame([], $pool->getLinks());
    }

    public function testASetIncludingTheCurrentStoreViewIsDeclaredFromAnyOfItsStoreViews(): void
    {
        $this->config->method('isHreflangEnabled')->willReturn(true);
        $pool = $this->pool([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ], 2);
        $this->assertSame(['en-GB', 'de-DE'], array_column($pool->getLinks(), 'hreflang'));
    }
}
