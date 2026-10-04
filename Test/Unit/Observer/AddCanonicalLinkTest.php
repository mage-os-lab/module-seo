<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Escaper;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\Asset\AssetInterface;
use Magento\Framework\View\Asset\GroupedCollection;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Observer\AddCanonicalLink;
use PHPUnit\Framework\TestCase;

/**
 * The CMS canonical: the resolver's URL for the page, added as a page asset the way core adds its
 * catalog canonicals, only on CMS pages, and never a second canonical.
 */
class AddCanonicalLinkTest extends TestCase
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

    public function testACmsPageGetsTheResolversUrlAsACanonicalAsset(): void
    {
        // The home page and every other CMS page carry cms_page_view; the resolver knows which is which.
        $this->observer(['cms_index_index', 'cms_page_view'], 'https://example.com/?a=1&b=2');

        $this->assertSame(
            [['https://example.com/?a=1&amp;b=2', 'canonical', ['attributes' => ['rel' => 'canonical']], null]],
            $this->added,
            'Added as core adds its catalog canonicals, with the URL escaped.'
        );
    }

    public function testACmsPageTheResolverCannotPlaceGetsNone(): void
    {
        $this->observer(['cms_page_view'], '');

        $this->assertSame([], $this->added);
    }

    public function testAPageWithoutTheCmsPageHandleGetsNone(): void
    {
        // Product and category canonicals are core's job; search, cart and the rest get none —
        // and neither does `/` when web/default/front serves something other than a CMS page.
        $resolver = $this->createMock(CmsPageResolver::class);
        $resolver->expects($this->never())->method('currentUrl');

        foreach (['catalog_product_view', 'checkout_cart_index', 'catalog_category_view'] as $handle) {
            $this->observer([$handle], '', resolver: $resolver);
        }

        $this->assertSame([], $this->added);
    }

    public function testAnExistingCanonicalIsLeftAlone(): void
    {
        // A canonical already added by core or another module must not get a second one.
        $asset = $this->createStub(AssetInterface::class);
        $asset->method('getContentType')->willReturn('canonical');

        $this->observer(['cms_index_index', 'cms_page_view'], 'https://example.com/', [$asset]);

        $this->assertSame([], $this->added);
    }

    public function testWithTheSettingOffACmsPageGetsNone(): void
    {
        $this->observer(['cms_page_view'], 'https://example.com/about', enabled: false);

        $this->assertSame([], $this->added);
    }

    public function testAResolverFailureLeavesThePageWithoutOne(): void
    {
        $resolver = $this->createStub(CmsPageResolver::class);
        $resolver->method('currentUrl')->willThrowException(new \RuntimeException('No store'));

        $this->observer(['cms_page_view'], '', resolver: $resolver);

        $this->assertSame([], $this->added);
    }

    /**
     * Run the observer once for a page with the given handles.
     *
     * @param string[] $handles The page's layout handles
     * @param string $url What the resolver returns for the page
     * @param AssetInterface[] $assets The page's assets already added
     * @param bool $enabled Use Canonical Link Meta Tag For CMS Pages
     * @param CmsPageResolver|null $resolver A resolver of the test's own, instead of one returning $url
     * @return void
     */
    private function observer(
        array $handles,
        string $url,
        array $assets = [],
        bool $enabled = true,
        ?CmsPageResolver $resolver = null
    ): void {
        $collection = $this->createStub(GroupedCollection::class);
        $collection->method('getAll')->willReturn($assets);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getAssetCollection')->willReturn($collection);
        $pageConfig->method('addRemotePageAsset')->willReturnCallback(
            function (string $url, string $type, array $properties = [], ?string $name = null) use ($pageConfig) {
                $this->added[] = [$url, $type, $properties, $name];
                return $pageConfig;
            }
        );

        if ($resolver === null) {
            $resolver = $this->createStub(CmsPageResolver::class);
            $resolver->method('currentUrl')->willReturn($url);
        }

        $config = $this->createStub(Config::class);
        $config->method('isCmsCanonicalEnabled')->willReturn($enabled);

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback(
            fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)
        );

        $processor = $this->createStub(ProcessorInterface::class);
        $processor->method('getHandles')->willReturn($handles);
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getUpdate')->willReturn($processor);

        (new AddCanonicalLink($pageConfig, $resolver, $config, $escaper))
            ->execute(new Observer(['event' => new Event(['layout' => $layout])]));
    }
}
