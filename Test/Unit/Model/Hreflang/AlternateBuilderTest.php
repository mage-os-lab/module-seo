<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\AlternateBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AlternateBuilderTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private Config&MockObject $config;

    /**
     * What an observer of the event does to the transport, if anything.
     *
     * @var callable|null
     */
    private $observer = null;

    /**
     * The website of the store view being rendered.
     *
     * @var int
     */
    private int $websiteId = 1;

    protected function setUp(): void
    {
        $this->config    = $this->createMock(Config::class);
        $this->observer  = null;
        $this->websiteId = 1;
    }

    /**
     * The builder, with a store manager answering $websiteId and an event manager running $observer.
     *
     * @return AlternateBuilder
     */
    private function alternateBuilder(): AlternateBuilder
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturnCallback(fn (): int => $this->websiteId);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $eventManager = $this->createStub(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            function (string $name, array $data): void {
                if ($this->observer !== null && $name === AlternateBuilder::EVENT_AFTER) {
                    ($this->observer)($data['transport']);
                }
            }
        );

        return new AlternateBuilder($this->config, $storeManager, $eventManager);
    }

    /**
     * @return array{hreflang: string, url: string, store_id: int}
     */
    private function link(string $hreflang, string $url, int $storeId): array
    {
        return ['hreflang' => $hreflang, 'url' => $url, 'store_id' => $storeId];
    }

    public function testSingleLocaleReturnsEmpty(): void
    {
        $builder = $this->alternateBuilder();
        $this->assertSame([], $builder->build([$this->link('en-GB', 'https://uk/p', 1)]));
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $builder = $this->alternateBuilder();
        $this->assertSame([], $builder->build([]));
    }

    public function testRegionLinksPreserved(): void
    {
        $builder = $this->alternateBuilder();
        $result  = $builder->build([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('en-US', 'https://us/p', 2),
        ]);
        $this->assertSame(['en-GB', 'en-US'], array_column($result, 'hreflang'));
    }

    public function testLanguageOnlyAddedForUniqueLanguage(): void
    {
        $this->config->method('isHreflangLanguageOnlyEnabled')->willReturn(true);
        $builder = $this->alternateBuilder();
        $result  = $builder->build([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $this->assertContains('en', array_column($result, 'hreflang'));
        $this->assertContains('de', array_column($result, 'hreflang'));
    }

    public function testLanguageOnlySkippedForSharedLanguage(): void
    {
        $this->config->method('isHreflangLanguageOnlyEnabled')->willReturn(true);
        $builder = $this->alternateBuilder();
        $result  = $builder->build([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('en-US', 'https://us/p', 2),
        ]);
        $this->assertNotContains('en', array_column($result, 'hreflang'));
    }

    public function testXDefaultUsesConfiguredStore(): void
    {
        $this->config->method('getHreflangXDefaultStoreId')->willReturn(2);
        $builder = $this->alternateBuilder();
        $result  = $builder->build([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $xDefault = array_values(array_filter($result, static fn ($l) => $l['hreflang'] === 'x-default'));
        $this->assertSame('https://de/p', $xDefault[0]['url']);
    }

    public function testXDefaultOmittedWhenNotConfigured(): void
    {
        $builder = $this->alternateBuilder();
        $result  = $builder->build([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);
        $this->assertNotContains('x-default', array_column($result, 'hreflang'));
    }

    public function testXDefaultIsReadForTheRenderingStoresWebsite(): void
    {
        // A .com website sending unmatched visitors to its US store, a .co.uk one to its UK store.
        $this->config->method('getHreflangXDefaultStoreId')->willReturnCallback(
            static fn (?int $websiteId): int => [1 => 2, 2 => 1][$websiteId] ?? 0
        );
        $links = [$this->link('en-GB', 'https://uk/p', 1), $this->link('en-US', 'https://us/p', 2)];

        $this->websiteId = 1;
        $comXDefault     = $this->xDefaultUrl($this->alternateBuilder()->build($links));

        $this->websiteId = 2;
        $ukXDefault      = $this->xDefaultUrl($this->alternateBuilder()->build($links));

        $this->assertSame('https://us/p', $comXDefault);
        $this->assertSame('https://uk/p', $ukXDefault);
    }

    public function testAStoreServingSeveralCodesGetsOneLinkPerCode(): void
    {
        $result = $this->alternateBuilder()->build([
            $this->link('es-MX', 'https://latam/p', 1),
            $this->link('es-AR', 'https://latam/p', 1),
            $this->link('es-ES', 'https://es/p', 2),
        ]);

        $this->assertSame(['es-MX', 'es-AR', 'es-ES'], array_column($result, 'hreflang'));
    }

    public function testOneStoreWithTwoCodesIsEnoughForAnAlternateSet(): void
    {
        // A single store view serving two regions has two annotations to make; the "fewer than two"
        // rule counts codes, not stores.
        $result = $this->alternateBuilder()->build([
            $this->link('en-GB', 'https://site/p', 1),
            $this->link('en-IE', 'https://site/p', 1),
        ]);

        $this->assertSame(['en-GB', 'en-IE'], array_column($result, 'hreflang'));
    }

    public function testTheOnlyStoreForALanguageGetsItsTagWhateverItsCodeCount(): void
    {
        // One Latin-American store serving two regions is still the only Spanish store.
        $this->config->method('isHreflangLanguageOnlyEnabled')->willReturn(true);

        $result = $this->alternateBuilder()->build([
            $this->link('es-MX', 'https://latam/p', 1),
            $this->link('es-AR', 'https://latam/p', 1),
            $this->link('en-GB', 'https://uk/p', 2),
        ]);

        $this->assertContains(['hreflang' => 'es', 'url' => 'https://latam/p'], $result);
    }

    public function testALanguageAlreadyClaimedBareIsNotAddedTwice(): void
    {
        $this->config->method('isHreflangLanguageOnlyEnabled')->willReturn(true);

        $result = $this->alternateBuilder()->build([
            $this->link('es-MX', 'https://latam/p', 1),
            $this->link('es', 'https://latam/p', 1),
            $this->link('en-GB', 'https://uk/p', 2),
        ]);

        $this->assertSame(1, \count(array_keys(array_column($result, 'hreflang'), 'es', true)));
    }

    public function testAnObserverCanRewriteTheAlternates(): void
    {
        $this->observer = static function (DataObject $transport): void {
            $alternates   = $transport->getData('alternates');
            $alternates[] = ['hreflang' => 'fr-FR', 'url' => 'https://partner.fr/p'];
            $transport->setData('alternates', $alternates);
        };

        $result = $this->alternateBuilder()->build([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);

        $this->assertContains('fr-FR', array_column($result, 'hreflang'));
    }

    public function testTheObserverSeesTheLinksTheSetWasBuiltFrom(): void
    {
        $seen = null;
        $this->observer = static function (DataObject $transport) use (&$seen): void {
            $seen = $transport->getData('region_links');
        };
        $links = [$this->link('en-GB', 'https://uk/p', 1), $this->link('de-DE', 'https://de/p', 2)];

        $this->alternateBuilder()->build($links);

        $this->assertSame($links, $seen);
    }

    public function testAnObserverLeavingSomethingOtherThanAnArrayIsIgnored(): void
    {
        // A broken observer must not be able to take the page's head, or the sitemap, down with it.
        $this->observer = static function (DataObject $transport): void {
            $transport->setData('alternates', 'not an array');
        };

        $result = $this->alternateBuilder()->build([
            $this->link('en-GB', 'https://uk/p', 1),
            $this->link('de-DE', 'https://de/p', 2),
        ]);

        $this->assertSame(['en-GB', 'de-DE'], array_column($result, 'hreflang'));
    }

    /**
     * @param array<int, array{hreflang: string, url: string}> $alternates
     * @return string|null
     */
    private function xDefaultUrl(array $alternates): ?string
    {
        foreach ($alternates as $alternate) {
            if ($alternate['hreflang'] === 'x-default') {
                return $alternate['url'];
            }
        }

        return null;
    }
}
