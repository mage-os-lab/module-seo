<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\CodeValidator;
use MageOS\Seo\Model\Hreflang\StoreLocaleMap;
use MageOS\Seo\Model\Store\CanonicalBaseUrl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StoreLocaleMapTest extends TestCase
{
    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface&MockObject $storeManager;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private ScopeConfigInterface&MockObject $scopeConfig;

    /**
     * @var Config&MockObject
     */
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfig  = $this->createMock(ScopeConfigInterface::class);
        $this->config       = $this->createMock(Config::class);
    }

    private function makeStore(int $id, bool $active, string $baseUrl, int $websiteId = 1): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getIsActive')->willReturn($active);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $store->method('getWebsiteId')->willReturn($websiteId);
        return $store;
    }

    /**
     * @param array<int, Store&MockObject> $stores
     * @param array<int, string> $locales
     * @param int[] $excluded
     * @param array<int, string[]> $codes Configured hreflang codes, by store ID
     */
    private function map(array $stores, array $locales, array $excluded = [], array $codes = []): StoreLocaleMap
    {
        $this->storeManager->method('getStores')->willReturn($stores);
        $this->config->method('getHreflangExcludedStoreIds')->willReturn($excluded);
        $this->config->method('getHreflangCodes')->willReturnCallback(
            static fn (int $storeId): array => $codes[$storeId] ?? []
        );
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, $scopeId) => $locales[(int) $scopeId] ?? ''
        );
        return $this->newMap();
    }

    /**
     * The map under test, its canonical base URLs being the stores' own without the slash.
     *
     * @return StoreLocaleMap
     */
    private function newMap(): StoreLocaleMap
    {
        $canonicalBaseUrl = $this->createStub(CanonicalBaseUrl::class);
        $canonicalBaseUrl->method('of')->willReturnCallback(
            static fn (Store $store): string => rtrim((string) $store->getBaseUrl(), '/')
        );

        return new StoreLocaleMap(
            $this->storeManager,
            $this->scopeConfig,
            $this->config,
            new CodeValidator(),
            $canonicalBaseUrl
        );
    }

    public function testFormatLocaleConvertsToBcp47(): void
    {
        $this->assertSame('en-GB', $this->newMap()->formatLocale('en_GB'));
    }

    public function testExtractLanguageReturnsBaseLanguage(): void
    {
        $this->assertSame('en', $this->newMap()->extractLanguage('en-GB'));
    }

    public function testBuildsMapForActiveStores(): void
    {
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://de/')],
            [1 => 'en_GB', 2 => 'de_DE']
        );
        $result = $map->getMap();
        $this->assertSame('https://uk', $result[1]['base_url']);
        $this->assertSame(['en-GB'], $result[1]['codes']);
        $this->assertSame(['de-DE'], $result[2]['codes']);
    }

    public function testInactiveStoresAreExcluded(): void
    {
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, false, 'https://de/')],
            [1 => 'en_GB', 2 => 'de_DE']
        );
        $this->assertArrayNotHasKey(2, $map->getMap());
    }

    public function testConfiguredExcludedStoresAreOmitted(): void
    {
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://de/')],
            [1 => 'en_GB', 2 => 'de_DE'],
            [2]
        );
        $this->assertArrayNotHasKey(2, $map->getMap());
        $this->assertArrayHasKey(1, $map->getMap());
    }

    public function testStoresWithoutLocaleAreSkipped(): void
    {
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://de/')],
            [1 => 'en_GB', 2 => '']
        );
        $this->assertArrayNotHasKey(2, $map->getMap());
    }

    public function testAnInactiveStoreEarlierInTheListDoesNotStopLaterStores(): void
    {
        // The skip must `continue`, not `break`: a lower-ID skipped store must
        // not terminate the loop and drop the valid stores after it.
        $result = $this->map(
            [$this->makeStore(1, false, 'https://uk/'), $this->makeStore(2, true, 'https://de/')],
            [1 => 'en_GB', 2 => 'de_DE']
        )->getMap();

        $this->assertArrayHasKey(2, $result);
    }

    public function testAStoreWithoutLocaleEarlierInTheListDoesNotStopLaterStores(): void
    {
        $result = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://de/')],
            [1 => '', 2 => 'de_DE']
        )->getMap();

        $this->assertArrayNotHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
    }

    public function testADuplicateLocaleEarlierInTheListDoesNotStopLaterStores(): void
    {
        // Store 2 is the duplicate (skipped); store 3 with a distinct locale must
        // still be reached — proving the dedupe skip is `continue`, not `break`.
        $result = $this->map(
            [
                $this->makeStore(1, true, 'https://us/'),
                $this->makeStore(2, true, 'https://us-b2b/'),
                $this->makeStore(3, true, 'https://de/'),
            ],
            [1 => 'en_US', 2 => 'en_US', 3 => 'de_DE']
        )->getMap();

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
        $this->assertArrayHasKey(3, $result);
    }

    public function testMapIsMemoised(): void
    {
        $this->storeManager->expects($this->once())->method('getStores')
            ->willReturn([$this->makeStore(1, true, 'https://uk/')]);
        $this->config->method('getHreflangExcludedStoreIds')->willReturn([]);
        $this->scopeConfig->method('getValue')->willReturn('en_GB');
        $map = $this->newMap();
        $map->getMap();
        $map->getMap();
    }

    public function testEachWebsiteGetsItsOwnMapWithinOneProcess(): void
    {
        // Cron generates every store view's sitemap in one process, moving from one website's
        // store views to another's; the first website's map must not be served to the second.
        $this->config->method('isHreflangSameWebsiteOnly')->willReturn(true);
        $uk = $this->makeStore(1, true, 'https://uk/', 1);
        $us = $this->makeStore(2, true, 'https://us/', 2);
        $current = $uk;
        $this->storeManager->method('getStore')->willReturnCallback(static function () use (&$current) {
            return $current;
        });

        $map = $this->map([$uk, $us], [1 => 'en_GB', 2 => 'en_US']);

        $this->assertSame([1], array_keys($map->getMap()));
        $current = $us;
        $this->assertSame([2], array_keys($map->getMap()));
    }

    public function testResetStateDropsTheMemoisedMapLikeReset(): void
    {
        $this->storeManager->expects($this->exactly(2))->method('getStores')
            ->willReturn([$this->makeStore(1, true, 'https://uk/')]);
        $this->config->method('getHreflangExcludedStoreIds')->willReturn([]);
        $this->scopeConfig->method('getValue')->willReturn('en_GB');
        $map = $this->newMap();

        $map->getMap();
        $map->_resetState();
        $map->getMap();
    }

    public function testStoresSharingALocaleAreDeduplicatedLowestStoreIdWins(): void
    {
        // Two hreflang entries with the same value are invalid; only one en-US
        // alternate may survive, deterministically the lowest store ID.
        $map = $this->map(
            [
                $this->makeStore(3, true, 'https://us-b2b/'),
                $this->makeStore(1, true, 'https://us/'),
                $this->makeStore(2, true, 'https://de/'),
            ],
            [1 => 'en_US', 2 => 'de_DE', 3 => 'en_US']
        );
        $result = $map->getMap();
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(3, $result);
        $this->assertArrayHasKey(2, $result);
    }

    public function testSameWebsiteOnlyExcludesOtherWebsitesStores(): void
    {
        $this->config->method('isHreflangSameWebsiteOnly')->willReturn(true);
        $currentStore = $this->makeStore(1, true, 'https://uk/', 1);
        $this->storeManager->method('getStore')->willReturn($currentStore);

        $map = $this->map(
            [
                $currentStore,
                $this->makeStore(2, true, 'https://de/', 1),
                $this->makeStore(3, true, 'https://b2b/', 2),
            ],
            [1 => 'en_GB', 2 => 'de_DE', 3 => 'fr_FR']
        );
        $result = $map->getMap();
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayNotHasKey(3, $result);
    }

    public function testConfiguredCodesReplaceTheLocale(): void
    {
        // An Irish store on an en_GB locale: Magento's locale cannot name its region.
        $result = $this->map(
            [$this->makeStore(1, true, 'https://ie/')],
            [1 => 'en_GB'],
            [],
            [1 => ['en-IE']]
        )->getMap();

        $this->assertSame(['en-IE'], $result[1]['codes']);
    }

    public function testOneStoreCanClaimSeveralCodes(): void
    {
        $result = $this->map(
            [$this->makeStore(1, true, 'https://latam/')],
            [1 => 'es_ES'],
            [],
            [1 => ['es-MX', 'es-AR', 'es-CL']]
        )->getMap();

        $this->assertSame(['es-MX', 'es-AR', 'es-CL'], $result[1]['codes']);
    }

    public function testStoresSharingALocaleBothSurviveOnceEitherSaysWhereItIsFor(): void
    {
        // The case the per-store dedupe got wrong: a UK store and an Irish store both on the
        // en_GB locale. Previously the Irish store vanished from hreflang altogether.
        $result = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://ie/')],
            [1 => 'en_GB', 2 => 'en_GB'],
            [],
            [2 => ['en-IE']]
        )->getMap();

        $this->assertSame(['en-GB'], $result[1]['codes']);
        $this->assertSame(['en-IE'], $result[2]['codes']);
    }

    public function testAClaimedCodeIsSkippedButTheStoreKeepsItsOthers(): void
    {
        // Deduplication is per code: store 2 loses es-MX to store 1, keeps es-AR.
        $result = $this->map(
            [$this->makeStore(1, true, 'https://mx/'), $this->makeStore(2, true, 'https://latam/')],
            [1 => 'es_MX', 2 => 'es_ES'],
            [],
            [2 => ['es-MX', 'es-AR']]
        )->getMap();

        $this->assertSame(['es-MX'], $result[1]['codes']);
        $this->assertSame(['es-AR'], $result[2]['codes']);
    }

    public function testAStoreWhoseEveryCodeIsTakenDropsOut(): void
    {
        $result = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://uk-b2b/')],
            [1 => 'en_GB', 2 => 'en_GB']
        )->getMap();

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }

    public function testTheLocaleIsNormalisedTheSameWayAsATypedCode(): void
    {
        // A lower-case locale and an upper-case one must not both be emitted as distinct codes.
        $result = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://uk2/')],
            [1 => 'en_gb', 2 => 'en_GB']
        )->getMap();

        $this->assertSame(['en-GB'], $result[1]['codes']);
        $this->assertArrayNotHasKey(2, $result);
    }
}
