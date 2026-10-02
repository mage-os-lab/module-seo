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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CmsPageHreflangResolverTest extends TestCase
{
    /**
     * @var CmsPageResolver&Stub
     */
    private CmsPageResolver&Stub $cmsPageResolver;

    /**
     * @var LinkBuilder&Stub
     */
    private LinkBuilder&Stub $linkBuilder;

    /**
     * @var ConfigRepository&Stub
     */
    private ConfigRepository&Stub $cmsConfigRepository;

    /**
     * @var UrlRewriteFetcher&Stub
     */
    private UrlRewriteFetcher&Stub $urlRewriteFetcher;

    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var CmsPageHreflangResolver
     */
    private CmsPageHreflangResolver $resolver;

    protected function setUp(): void
    {
        $this->cmsPageResolver     = $this->createStub(CmsPageResolver::class);
        $this->linkBuilder         = $this->createStub(LinkBuilder::class);
        $this->cmsConfigRepository = $this->createStub(ConfigRepository::class);
        $this->urlRewriteFetcher   = $this->createStub(UrlRewriteFetcher::class);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->resolver = $this->resolver();
    }

    public function testHandlesCmsAndHome(): void
    {
        $this->assertSame(['cms_page_view', 'cms_index_index'], $this->resolver->getHandles());
    }

    public function testHomePageUsesTheHomeLinks(): void
    {
        $cmsPageResolver = $this->createMock(CmsPageResolver::class);
        $cmsPageResolver->method('isHomePage')->willReturn(true);
        $links = [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/', 'store_id' => 2],
        ];
        $this->linkBuilder->method('buildHome')->willReturn($links);
        $cmsPageResolver->expects($this->never())->method('resolve');

        $this->assertSame($links, $this->resolver(cmsPageResolver: $cmsPageResolver)->getLinks());
    }

    public function testAPageOutsideAnyGroupLinksToItselfInOtherStoreViews(): void
    {
        $this->givenPage(12);
        $cmsConfigRepository = $this->createMock(ConfigRepository::class);
        $cmsConfigRepository->method('getHreflangGroup')->with(12)->willReturn(null);
        $urlRewriteFetcher = $this->createMock(UrlRewriteFetcher::class);
        $urlRewriteFetcher->expects($this->never())->method('fetchForCmsGroup');

        $links = [['hreflang' => 'en-GB', 'url' => 'https://uk/about-us', 'store_id' => 1]];
        $linkBuilder = $this->createMock(LinkBuilder::class);
        $linkBuilder->method('build')->with('cms-page', 12)->willReturn($links);

        $resolver = $this->resolver(
            linkBuilder: $linkBuilder,
            cmsConfigRepository: $cmsConfigRepository,
            urlRewriteFetcher: $urlRewriteFetcher
        );

        $this->assertSame($links, $resolver->getLinks());
    }

    public function testAPageInAGroupLinksToEachStoreViewsTranslation(): void
    {
        $this->givenPage(12);
        $linkBuilder         = $this->createMock(LinkBuilder::class);
        $cmsConfigRepository = $this->createMock(ConfigRepository::class);
        $urlRewriteFetcher   = $this->createMock(UrlRewriteFetcher::class);
        $this->givenOwnUrl($linkBuilder, 12, 'https://uk/about-us');
        $links = $this->givenGroup($linkBuilder, $cmsConfigRepository, $urlRewriteFetcher, 12, 'about-us');

        $resolver = $this->resolver(
            linkBuilder: $linkBuilder,
            cmsConfigRepository: $cmsConfigRepository,
            urlRewriteFetcher: $urlRewriteFetcher
        );

        $this->assertSame($links, $resolver->getLinks());
    }

    /**
     * Two translations in one store view: the group names page 12's URL, so page 13 is the loser
     * and must not declare page 12 as its own-language version.
     */
    public function testATranslationThatIsNotItsGroupsPageForTheStoreViewDeclaresNothing(): void
    {
        $this->givenPage(13);
        $linkBuilder         = $this->createMock(LinkBuilder::class);
        $cmsConfigRepository = $this->createMock(ConfigRepository::class);
        $urlRewriteFetcher   = $this->createMock(UrlRewriteFetcher::class);
        $this->givenOwnUrl($linkBuilder, 13, 'https://uk/about-us-2');
        $this->givenGroup($linkBuilder, $cmsConfigRepository, $urlRewriteFetcher, 13, 'about-us');

        $resolver = $this->resolver(
            linkBuilder: $linkBuilder,
            cmsConfigRepository: $cmsConfigRepository,
            urlRewriteFetcher: $urlRewriteFetcher
        );

        $this->assertSame([], $resolver->getLinks());
    }

    public function testAGroupWithNoPageForTheStoreViewDeclaresNothing(): void
    {
        $this->givenPage(12);
        $linkBuilder = $this->createMock(LinkBuilder::class);
        $this->givenOwnUrl($linkBuilder, 12, 'https://uk/about-us');
        $cmsConfigRepository = $this->createMock(ConfigRepository::class);
        $cmsConfigRepository->method('getHreflangGroup')->with(12)->willReturn('about-us');
        $this->urlRewriteFetcher->method('fetchForCmsGroup')->willReturn([2 => 'ueber-uns']);
        $linkBuilder->method('buildFromPaths')->willReturn([
            ['hreflang' => 'de-DE', 'url' => 'https://de/ueber-uns', 'store_id' => 2],
        ]);

        $resolver = $this->resolver(linkBuilder: $linkBuilder, cmsConfigRepository: $cmsConfigRepository);

        $this->assertSame([], $resolver->getLinks());
    }

    public function testReturnsEmptyWhenCmsPageNotResolved(): void
    {
        $this->cmsPageResolver->method('isHomePage')->willReturn(false);
        $this->cmsPageResolver->method('resolve')->willReturn(null);
        $this->assertSame([], $this->resolver->getLinks());
    }

    /**
     * The resolver under test, over the given doubles or this test's stubs.
     *
     * @param CmsPageResolver|null $cmsPageResolver
     * @param LinkBuilder|null $linkBuilder
     * @param ConfigRepository|null $cmsConfigRepository
     * @param UrlRewriteFetcher|null $urlRewriteFetcher
     * @return CmsPageHreflangResolver
     */
    private function resolver(
        ?CmsPageResolver $cmsPageResolver = null,
        ?LinkBuilder $linkBuilder = null,
        ?ConfigRepository $cmsConfigRepository = null,
        ?UrlRewriteFetcher $urlRewriteFetcher = null
    ): CmsPageHreflangResolver {
        return new CmsPageHreflangResolver(
            $cmsPageResolver ?? $this->cmsPageResolver,
            $linkBuilder ?? $this->linkBuilder,
            $cmsConfigRepository ?? $this->cmsConfigRepository,
            $urlRewriteFetcher ?? $this->urlRewriteFetcher,
            $this->storeManager,
            new SelfReference()
        );
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
     * @param LinkBuilder&MockObject $linkBuilder
     * @param int $pageId
     * @param string $url
     * @return void
     */
    private function givenOwnUrl(LinkBuilder&MockObject $linkBuilder, int $pageId, string $url): void
    {
        $linkBuilder->method('build')->with('cms-page', $pageId)
            ->willReturn([['hreflang' => 'en-GB', 'url' => $url, 'store_id' => 1]]);
    }

    /**
     * The page is in the given translation group, whose store view 1 page is at /about-us.
     *
     * @param LinkBuilder&MockObject $linkBuilder
     * @param ConfigRepository&MockObject $cmsConfigRepository
     * @param UrlRewriteFetcher&MockObject $urlRewriteFetcher
     * @param int $pageId
     * @param string $group
     * @return array<int,array{hreflang:string,url:string,store_id:int}> The group's links
     */
    private function givenGroup(
        LinkBuilder&MockObject $linkBuilder,
        ConfigRepository&MockObject $cmsConfigRepository,
        UrlRewriteFetcher&MockObject $urlRewriteFetcher,
        int $pageId,
        string $group
    ): array {
        $cmsConfigRepository->method('getHreflangGroup')->with($pageId)->willReturn($group);
        $urlRewriteFetcher->method('fetchForCmsGroup')->with($group)
            ->willReturn([1 => 'about-us', 2 => 'ueber-uns']);

        $links = [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/about-us', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/ueber-uns', 'store_id' => 2],
        ];
        $linkBuilder->method('buildFromPaths')->with([1 => 'about-us', 2 => 'ueber-uns'])->willReturn($links);

        return $links;
    }
}
