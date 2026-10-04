<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Escaper;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\ResolverPool;
use MageOS\Seo\Observer\AddHreflangLinks;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The hreflang alternates: one named page asset per code, added the way core adds its canonicals.
 */
class AddHreflangLinksTest extends TestCase
{
    /**
     * Each addRemotePageAsset() call, as [url, content type, properties, name].
     *
     * @var array<int, array{0:string,1:string,2:mixed[],3:string|null}>
     */
    private array $added = [];

    protected function setUp(): void
    {
        $this->added = [];
    }

    public function testEachAlternateIsANamedHreflangAsset(): void
    {
        $this->observer([
            ['hreflang' => 'en-GB', 'url' => 'https://example.co.uk/shoes?colour=red&size=9'],
            ['hreflang' => 'x-default', 'url' => 'https://example.com/shoes'],
        ]);

        $this->assertSame(
            [
                [
                    'https://example.co.uk/shoes?colour=red&amp;size=9',
                    'hreflang',
                    ['attributes' => ['rel' => 'alternate', 'hreflang' => 'en-GB']],
                    'mageos_seo_hreflang_en-GB',
                ],
                [
                    'https://example.com/shoes',
                    'hreflang',
                    ['attributes' => ['rel' => 'alternate', 'hreflang' => 'x-default']],
                    'mageos_seo_hreflang_x-default',
                ],
            ],
            $this->added
        );
    }

    public function testWithTheSettingOffThePoolIsNotAsked(): void
    {
        $pool = $this->createMock(ResolverPool::class);
        $pool->expects($this->never())->method('getLinks');

        $this->observer([], enabled: false, pool: $pool);

        $this->assertSame([], $this->added);
    }

    public function testAResolverFailureIsLoggedAndThePageKeepsRendering(): void
    {
        $pool = $this->createStub(ResolverPool::class);
        $pool->method('getLinks')->willThrowException(new \RuntimeException('Rewrite table gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('Rewrite table gone'));

        $this->observer([], pool: $pool, logger: $logger);

        $this->assertSame([], $this->added);
    }

    public function testAUrlTheEscaperRefusesIsLeftOut(): void
    {
        $this->observer([
            ['hreflang' => 'de-DE', 'url' => 'javascript:alert(1)'],
            ['hreflang' => 'fr-FR', 'url' => 'https://example.fr/'],
        ]);

        $this->assertSame(['mageos_seo_hreflang_fr-FR'], array_column($this->added, 3));
    }

    /**
     * Run the observer once over the given alternates.
     *
     * @param array<int, array{hreflang: string, url: string}> $links What the pool returns
     * @param bool $enabled Enable Hreflang Tags
     * @param ResolverPool|null $pool A pool of the test's own, instead of one returning $links
     * @param LoggerInterface|null $logger
     * @return void
     */
    private function observer(
        array $links,
        bool $enabled = true,
        ?ResolverPool $pool = null,
        ?LoggerInterface $logger = null
    ): void {
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('addRemotePageAsset')->willReturnCallback(
            function (string $url, string $type, array $properties = [], ?string $name = null) use ($pageConfig) {
                $this->added[] = [$url, $type, $properties, $name];
                return $pageConfig;
            }
        );

        if ($pool === null) {
            $pool = $this->createStub(ResolverPool::class);
            $pool->method('getLinks')->willReturn($links);
        }

        $config = $this->createStub(Config::class);
        $config->method('isHreflangEnabled')->willReturn($enabled);

        // Escaper::escapeUrl() refuses a javascript: URL by returning ''.
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback(
            fn (string $value): string => str_starts_with($value, 'javascript:')
                ? ''
                : htmlspecialchars($value, ENT_QUOTES)
        );

        (new AddHreflangLinks(
            $pageConfig,
            $pool,
            $config,
            $escaper,
            $logger ?? $this->createStub(LoggerInterface::class)
        ))->execute(new Observer(['event' => new Event([])]));
    }
}
