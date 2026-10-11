<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\MetaTag\Provider;

use Magento\Cms\Model\Page;
use Magento\Cms\Model\Template\Filter;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\MetaTag\Provider\CmsPageMetaProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Review M10: a CMS page's og:image is its first image as a URL a crawler can fetch.
 *
 * Page Builder and the WYSIWYG editor store an image as a `{{media url=…}}` directive, so the src has
 * to go through the page filter. Only the src does: running the whole content through it rendered
 * every widget on the page a second time, on every uncached request, to find one image.
 */
class CmsPageMetaProviderTest extends TestCase
{
    private const BASE_URL = 'https://example.com/';

    /**
     * @return void
     */
    public function testADirectiveSrcIsFilteredOnItsOwn(): void
    {
        $filter = $this->createMock(Filter::class);
        $filter->expects($this->once())
            ->method('filter')
            ->with('{{media url=wysiwyg/home/hero.jpg}}')
            ->willReturn('https://example.com/media/wysiwyg/home/hero.jpg');

        $tags = $this->tags(
            '<p>{{widget type="Magento\CatalogWidget\Block\Product\ProductsList" products_count="10"}}</p>'
            . '<img src="{{media url=wysiwyg/home/hero.jpg}}" alt="Hero">'
            . '<img src="{{media url=wysiwyg/home/second.jpg}}" alt="Second">',
            $filter
        );

        $this->assertSame('https://example.com/media/wysiwyg/home/hero.jpg', $tags['og:image'] ?? null);
    }

    /**
     * @return void
     */
    public function testAnAbsoluteSrcIsKept(): void
    {
        $tags = $this->tags('<img src="https://cdn.example.com/a.jpg">', $this->passThrough());

        $this->assertSame('https://cdn.example.com/a.jpg', $tags['og:image'] ?? null);
    }

    /**
     * @return void
     */
    public function testARelativeSrcIsMadeAbsolute(): void
    {
        $tags = $this->tags('<img src="/media/wysiwyg/a.jpg">', $this->passThrough());

        $this->assertSame('https://example.com/media/wysiwyg/a.jpg', $tags['og:image'] ?? null);
    }

    /**
     * @return void
     */
    public function testAPageWithoutAnImageHasNoOgImage(): void
    {
        $filter = $this->createMock(Filter::class);
        $filter->expects($this->never())->method('filter');

        $tags = $this->tags('<p>{{widget type="Magento\Cms\Block\Widget\Block" block_id="1"}}</p>', $filter);

        $this->assertArrayNotHasKey('og:image', $tags);
        $this->assertSame('About us', $tags['og:title'] ?? null);
    }

    /**
     * A failure is logged rather than swallowed: the page then has no Open Graph tags, and the log
     * says why.
     *
     * @return void
     */
    public function testAFailureIsLogged(): void
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('filter')->willThrowException(new \RuntimeException('media directive failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('media directive failed'));

        $this->assertSame([], $this->tags('<img src="{{media url=wysiwyg/a.jpg}}">', $filter, $logger));
    }

    /**
     * The provider's tags for a CMS page with the given content: property => content.
     *
     * @param string $content
     * @param Filter $filter
     * @param LoggerInterface|null $logger
     * @return array<string, string>
     */
    private function tags(string $content, Filter $filter, ?LoggerInterface $logger = null): array
    {
        $page = $this->createStub(Page::class);
        $page->method('getTitle')->willReturn('About us');
        $page->method('getContent')->willReturn($content);
        $page->method('getMetaDescription')->willReturn('');
        $resolver = $this->createStub(CmsPageResolver::class);
        $resolver->method('resolve')->willReturn($page);
        $resolver->method('currentUrl')->willReturn(self::BASE_URL . 'about-us');
        $config = $this->createStub(Config::class);
        $config->method('isOgTagsEnabled')->willReturn(true);
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn(self::BASE_URL);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $filterProvider = $this->createStub(FilterProvider::class);
        $filterProvider->method('getPageFilter')->willReturn($filter);

        $provider = new CmsPageMetaProvider(
            $resolver,
            $config,
            $storeManager,
            $filterProvider,
            $logger ?? $this->createStub(LoggerInterface::class)
        );

        $tags = [];
        foreach ($provider->getMetaTags() as $tag) {
            $tags[(string) $tag['property']] = (string) $tag['content'];
        }

        return $tags;
    }

    /**
     * A page filter that returns what it is given.
     *
     * @return Filter
     */
    private function passThrough(): Filter
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('filter')->willReturnArgument(0);

        return $filter;
    }
}
