<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\CodeList;
use MageOS\Seo\Model\Hreflang\CodeValidator;
use MageOS\Seo\Model\Hreflang\StoreLocaleMap;
use MageOS\Seo\Model\Store\CanonicalBaseUrl;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StoreLocaleMapTest extends TestCase
{
    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var Config&Stub
     */
    private Config&Stub $config;

    protected function setUp(): void
    {
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->config       = $this->createStub(Config::class);
    }

    private function makeStore(int $id, bool $active, string $baseUrl, int $websiteId = 1): Store&Stub
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getIsActive')->willReturn($active);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $store->method('getWebsiteId')->willReturn($websiteId);
        return $store;
    }

    /**
     * @param array<int, Store&Stub> $stores
     * @param array<int, string> $locales
     * @param int[] $excluded
     * @param array<int, string[]> $codes Configured hreflang codes, by store ID
     * @param int[] $hreflangOff Store IDs with hreflang disabled
     * @param LoggerInterface|null $logger
     * @param CacheInterface|null $cache
     */
    private function map(
        array $stores,
        array $locales,
        array $excluded = [],
        array $codes = [],
        array $hreflangOff = [],
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null
    ): StoreLocaleMap {
        $this->storeManager->method('getStores')->willReturn($stores);
        $this->config->method('getHreflangExcludedStoreIds')->willReturn($excluded);
        $this->config->method('getHreflangCodes')->willReturnCallback(
            static fn (int $storeId): array => $codes[$storeId] ?? []
        );
        $this->config->method('getLocaleCode')->willReturnCallback(
            static fn (int $storeId): string => $locales[$storeId] ?? ''
        );
        $this->config->method('isHreflangEnabled')->willReturnCallback(
            static fn (int|string|null $storeId): bool => !\in_array((int) $storeId, $hreflangOff, true)
        );
        return $this->newMap(logger: $logger, cache: $cache);
    }

    /**
     * The map under test, its canonical base URLs being the stores' own without the slash.
     *
     * @param StoreManagerInterface|null $storeManager Defaults to this test's stub
     * @param LoggerInterface|null $logger
     * @param CacheInterface|null $cache
     * @return StoreLocaleMap
     */
    private function newMap(
        ?StoreManagerInterface $storeManager = null,
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null
    ): StoreLocaleMap {
        $canonicalBaseUrl = $this->createStub(CanonicalBaseUrl::class);
        $canonicalBaseUrl->method('of')->willReturnCallback(
            static fn (Store $store): string => rtrim((string) $store->getBaseUrl(), '/')
        );

        return new StoreLocaleMap(
            $storeManager ?? $this->storeManager,
            $this->config,
            new CodeValidator(),
            $canonicalBaseUrl,
            $this->codeList(),
            $cache ?? $this->createStub(CacheInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * A CodeList by its rules — normalised, well-formed, a known region, each once — whose
     * country directory knows every region but UK. CodeListTest covers the real one.
     *
     * @return CodeList
     */
    private function codeList(): CodeList
    {
        $codeList = $this->createStub(CodeList::class);
        $codeList->method('check')->willReturnCallback(
            static function (array $entered): array {
                $validator = new CodeValidator();
                $codes     = [];
                $rejected  = [];
                foreach ($entered as $code) {
                    $candidate = $validator->normalise((string) $code);
                    if (!$validator->isValid($candidate) || $validator->region($candidate) === 'UK') {
                        $rejected[] = $code;
                    } elseif (!\in_array($candidate, $codes, true)) {
                        $codes[] = $candidate;
                    }
                }

                return [
                    'codes' => $codes, 'rejected' => $rejected,
                    'malformed' => [], 'duplicates' => [], 'unknown_regions' => [],
                ];
            }
        );

        return $codeList;
    }

    public function testAStoreWithHreflangOffIsNoTarget(): void
    {
        // Issue #28: an enabled store linking to one that links back to nothing.
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://de/')],
            [1 => 'en_GB', 2 => 'de_DE'],
            hreflangOff: [2]
        );

        $this->assertSame([1], array_keys($map->getMap()));
    }

    public function testAStoreWithHreflangOffClaimsNoCode(): void
    {
        // Issue #28: a disabled store with a lower ID took the shared code first.
        $map = $this->map(
            [$this->makeStore(1, true, 'https://a/'), $this->makeStore(2, true, 'https://b/')],
            [1 => 'en_GB', 2 => 'en_GB'],
            hreflangOff: [1]
        );

        $this->assertSame([2 => ['base_url' => 'https://b', 'codes' => ['en-GB']]], $map->getMap());
    }

    public function testConfiguredCodesAreCheckedAsTheAdminChecksThem(): void
    {
        // Issue #29: a value from config.php, env.php or the database skips the admin's check.
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/')],
            [1 => 'en_GB'],
            codes: [1 => ['EN_gb', 'not-valid', 'en-gb', 'en-UK', 'en-IE']]
        );

        $this->assertSame(['en-GB', 'en-IE'], $map->getMap()[1]['codes']);
    }

    public function testAStoreWithNoValidConfiguredCodeUsesItsLocale(): void
    {
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/')],
            [1 => 'en_GB'],
            codes: [1 => ['not-valid']]
        );

        $this->assertSame(['en-GB'], $map->getMap()[1]['codes']);
    }

    public function testAnInvalidLocaleCodeGivesNoCode(): void
    {
        $map = $this->map(
            [$this->makeStore(1, true, 'https://uk/'), $this->makeStore(2, true, 'https://x/')],
            [1 => 'en_GB', 2 => 'eng_GBR']
        );

        $this->assertSame([1], array_keys($map->getMap()));
    }

    public function testARejectedCodeIsLoggedAtMostOnceEveryFiveMinutes(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->logicalAnd($this->stringContains('not-valid'), $this->stringContains('2'))
        );
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnOnConsecutiveCalls(false, '1');
        $cache->expects($this->once())->method('save')->with('1', $this->anything(), [], 300);

        $map = $this->map(
            [$this->makeStore(2, true, 'https://uk/')],
            [2 => 'en_GB'],
            codes: [2 => ['en-GB', 'not-valid']],
            logger: $logger,
            cache: $cache
        );
        $map->getMap();
        $map->reset();
        $map->getMap();
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
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())->method('getStores')
            ->willReturn([$this->makeStore(1, true, 'https://uk/')]);
        $this->config->method('getHreflangExcludedStoreIds')->willReturn([]);
        $this->config->method('getLocaleCode')->willReturn('en_GB');
        $map = $this->newMap($storeManager);
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
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->exactly(2))->method('getStores')
            ->willReturn([$this->makeStore(1, true, 'https://uk/')]);
        $this->config->method('getHreflangExcludedStoreIds')->willReturn([]);
        $this->config->method('getLocaleCode')->willReturn('en_GB');
        $map = $this->newMap($storeManager);

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
