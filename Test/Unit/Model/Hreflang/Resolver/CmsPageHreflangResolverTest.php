<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang\Resolver;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\Request\Http;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\Resolver\CmsPageHreflangResolver;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CmsPageHreflangResolverTest extends TestCase
{
    /**
     * @var CmsPageResolver&MockObject
     */
    private CmsPageResolver&MockObject $cmsPageResolver;

    /**
     * @var Http&MockObject
     */
    private Http&MockObject $request;

    /**
     * @var LinkBuilder&MockObject
     */
    private LinkBuilder&MockObject $linkBuilder;

    /**
     * @var ConfigRepository&MockObject
     */
    private ConfigRepository&MockObject $cmsConfigRepository;

    /**
     * @var UrlRewriteFetcher&MockObject
     */
    private UrlRewriteFetcher&MockObject $urlRewriteFetcher;

    /**
     * @var CmsPageHreflangResolver
     */
    private CmsPageHreflangResolver $resolver;

    protected function setUp(): void
    {
        $this->cmsPageResolver     = $this->createMock(CmsPageResolver::class);
        $this->request             = $this->createMock(Http::class);
        $this->linkBuilder         = $this->createMock(LinkBuilder::class);
        $this->cmsConfigRepository = $this->createMock(ConfigRepository::class);
        $this->urlRewriteFetcher   = $this->createMock(UrlRewriteFetcher::class);
        $this->resolver            = new CmsPageHreflangResolver(
            $this->cmsPageResolver,
            $this->request,
            $this->linkBuilder,
            $this->cmsConfigRepository,
            $this->urlRewriteFetcher
        );
    }

    public function testHandlesCmsAndHome(): void
    {
        $this->assertSame(['cms_page_view', 'cms_index_index'], $this->resolver->getHandles());
    }

    public function testHomePageUsesTheHomeLinks(): void
    {
        $this->request->method('getPathInfo')->willReturn('/');
        $links = [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/', 'store_id' => 2],
        ];
        $this->linkBuilder->method('buildHome')->willReturn($links);
        $this->cmsPageResolver->expects($this->never())->method('resolve');

        $this->assertSame($links, $this->resolver->getLinks());
    }

    public function testAPageOutsideAnyGroupLinksToItselfInOtherStoreViews(): void
    {
        $this->givenPage(12);
        $this->cmsConfigRepository->method('getHreflangGroup')->with(12)->willReturn(null);
        $this->urlRewriteFetcher->expects($this->never())->method('fetchForCmsGroup');

        $links = [['hreflang' => 'en-GB', 'url' => 'https://uk/about-us', 'store_id' => 1]];
        $this->linkBuilder->method('build')->with('cms-page', 12)->willReturn($links);

        $this->assertSame($links, $this->resolver->getLinks());
    }

    public function testAPageInAGroupLinksToEachStoreViewsTranslation(): void
    {
        $this->givenPage(12);
        $this->cmsConfigRepository->method('getHreflangGroup')->with(12)->willReturn('about-us');
        $this->urlRewriteFetcher->method('fetchForCmsGroup')->with('about-us')
            ->willReturn([1 => 'about-us', 2 => 'ueber-uns']);
        $this->linkBuilder->expects($this->never())->method('build');

        $links = [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/about-us', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/ueber-uns', 'store_id' => 2],
        ];
        $this->linkBuilder->method('buildFromPaths')->with([1 => 'about-us', 2 => 'ueber-uns'])->willReturn($links);

        $this->assertSame($links, $this->resolver->getLinks());
    }

    public function testReturnsEmptyWhenCmsPageNotResolved(): void
    {
        $this->request->method('getPathInfo')->willReturn('/missing');
        $this->cmsPageResolver->method('resolve')->willReturn(null);
        $this->assertSame([], $this->resolver->getLinks());
    }

    /**
     * The request is for a CMS page with the given ID.
     *
     * @param int $pageId
     * @return void
     */
    private function givenPage(int $pageId): void
    {
        $this->request->method('getPathInfo')->willReturn('/about-us');
        $page = $this->createStub(PageInterface::class);
        $page->method('getId')->willReturn($pageId);
        $this->cmsPageResolver->method('resolve')->willReturn($page);
    }
}
