<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang\Resolver;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\Resolver\CmsPageHreflangResolver;
use MageOS\Seo\Model\Hreflang\SelfReference;
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
        $this->linkBuilder         = $this->createMock(LinkBuilder::class);
        $this->cmsConfigRepository = $this->createMock(ConfigRepository::class);
        $this->urlRewriteFetcher   = $this->createMock(UrlRewriteFetcher::class);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->resolver = new CmsPageHreflangResolver(
            $this->cmsPageResolver,
            $this->linkBuilder,
            $this->cmsConfigRepository,
            $this->urlRewriteFetcher,
            $storeManager,
            new SelfReference()
        );
    }

    public function testHandlesCmsAndHome(): void
    {
        $this->assertSame(['cms_page_view', 'cms_index_index'], $this->resolver->getHandles());
    }

    public function testHomePageUsesTheHomeLinks(): void
    {
        $this->cmsPageResolver->method('isHomePage')->willReturn(true);
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
        $this->givenOwnUrl(12, 'https://uk/about-us');
        $links = $this->givenGroup(12, 'about-us');

        $this->assertSame($links, $this->resolver->getLinks());
    }

    /**
     * Two translations in one store view: the group names page 12's URL, so page 13 is the loser
     * and must not declare page 12 as its own-language version.
     */
    public function testATranslationThatIsNotItsGroupsPageForTheStoreViewDeclaresNothing(): void
    {
        $this->givenPage(13);
        $this->givenOwnUrl(13, 'https://uk/about-us-2');
        $this->givenGroup(13, 'about-us');

        $this->assertSame([], $this->resolver->getLinks());
    }

    public function testAGroupWithNoPageForTheStoreViewDeclaresNothing(): void
    {
        $this->givenPage(12);
        $this->givenOwnUrl(12, 'https://uk/about-us');
        $this->cmsConfigRepository->method('getHreflangGroup')->with(12)->willReturn('about-us');
        $this->urlRewriteFetcher->method('fetchForCmsGroup')->willReturn([2 => 'ueber-uns']);
        $this->linkBuilder->method('buildFromPaths')->willReturn([
            ['hreflang' => 'de-DE', 'url' => 'https://de/ueber-uns', 'store_id' => 2],
        ]);

        $this->assertSame([], $this->resolver->getLinks());
    }

    public function testReturnsEmptyWhenCmsPageNotResolved(): void
    {
        $this->cmsPageResolver->method('isHomePage')->willReturn(false);
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
        $this->cmsPageResolver->method('isHomePage')->willReturn(false);
        $page = $this->createStub(PageInterface::class);
        $page->method('getId')->willReturn($pageId);
        $this->cmsPageResolver->method('resolve')->willReturn($page);
    }

    /**
     * The page's own URL rewrite resolves to the given URL in store view 1.
     *
     * @param int $pageId
     * @param string $url
     * @return void
     */
    private function givenOwnUrl(int $pageId, string $url): void
    {
        $this->linkBuilder->method('build')->with('cms-page', $pageId)
            ->willReturn([['hreflang' => 'en-GB', 'url' => $url, 'store_id' => 1]]);
    }

    /**
     * The page is in the given translation group, whose store view 1 page is at /about-us.
     *
     * @param int $pageId
     * @param string $group
     * @return array<int,array{hreflang:string,url:string,store_id:int}> The group's links
     */
    private function givenGroup(int $pageId, string $group): array
    {
        $this->cmsConfigRepository->method('getHreflangGroup')->with($pageId)->willReturn($group);
        $this->urlRewriteFetcher->method('fetchForCmsGroup')->with($group)
            ->willReturn([1 => 'about-us', 2 => 'ueber-uns']);

        $links = [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/about-us', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/ueber-uns', 'store_id' => 2],
        ];
        $this->linkBuilder->method('buildFromPaths')->with([1 => 'about-us', 2 => 'ueber-uns'])->willReturn($links);

        return $links;
    }
}
