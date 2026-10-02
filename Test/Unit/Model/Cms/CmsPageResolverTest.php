<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\HomePageLoader;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The current CMS page, whether the request is for the home page, and the page's URL.
 *
 * The home page is recognised as core's router recognises it — an empty path — and loaded by
 * HomePageLoader, as core loads it (its own test covers how).
 */
class CmsPageResolverTest extends TestCase
{
    /**
     * @var PageRepositoryInterface&Stub
     */
    private PageRepositoryInterface&Stub $pageRepository;

    /**
     * @var Http&Stub
     */
    private Http&Stub $request;

    /**
     * @var GetPageByIdentifierInterface&Stub
     */
    private GetPageByIdentifierInterface&Stub $getPageByIdentifier;

    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var HomePageLoader&Stub
     */
    private HomePageLoader&Stub $homePageLoader;

    /**
     * @var CmsPageResolver
     */
    private CmsPageResolver $resolver;

    protected function setUp(): void
    {
        $this->pageRepository      = $this->createStub(PageRepositoryInterface::class);
        $this->request             = $this->createStub(Http::class);
        $this->getPageByIdentifier = $this->createStub(GetPageByIdentifierInterface::class);
        $this->homePageLoader      = $this->createStub(HomePageLoader::class);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->resolver = $this->resolver();
    }

    public function testPageIdParamLoadsByIdWithoutTheIdentifierService(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getParam')->with('page_id')->willReturn(5);
        $page = $this->createStub(PageInterface::class);
        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository->method('getById')->with(5)->willReturn($page);
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->expects($this->never())->method('execute');

        $resolver = $this->resolver(
            pageRepository: $pageRepository,
            request: $request,
            getPageByIdentifier: $getPageByIdentifier
        );

        $this->assertSame($page, $resolver->resolve());
    }

    public function testPathInfoIdentifierResolvesViaTheService(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/about-us');
        $page = $this->createStub(PageInterface::class);
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->method('execute')->with('about-us', 1)->willReturn($page);

        $this->assertSame($page, $this->resolver(getPageByIdentifier: $getPageByIdentifier)->resolve());
    }

    public function testAnEmptyPathResolvesTheCurrentStoreViewsHomePage(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/');
        $page = $this->createStub(PageInterface::class);
        $homePageLoader = $this->createMock(HomePageLoader::class);
        $homePageLoader->expects($this->once())->method('load')->with(1)->willReturn($page);
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->expects($this->never())->method('execute');

        $resolver = $this->resolver(getPageByIdentifier: $getPageByIdentifier, homePageLoader: $homePageLoader);

        $this->assertSame($page, $resolver->resolve());
    }

    public function testMissingPageResolvesToNullAndIsMemoised(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/missing');
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->expects($this->once())->method('execute')
            ->willThrowException(new NoSuchEntityException(__('not found')));
        $resolver = $this->resolver(getPageByIdentifier: $getPageByIdentifier);

        $this->assertNull($resolver->resolve());
        // Second call must not hit the service again (the null result is memoised).
        $this->assertNull($resolver->resolve());
    }

    public function testResetStateForcesAFreshResolution(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/about-us');
        $page = $this->createStub(PageInterface::class);
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->expects($this->exactly(2))->method('execute')->willReturn($page);
        $resolver = $this->resolver(getPageByIdentifier: $getPageByIdentifier);

        $resolver->resolve();
        $resolver->_resetState();
        $resolver->resolve();
    }

    public function testTheHomePageIsTheRequestWithAnEmptyPath(): void
    {
        $this->request->method('getPathInfo')->willReturnOnConsecutiveCalls('', '/', '/about-us', '/cms/index/index');

        $this->assertTrue($this->resolver->isHomePage());
        $this->assertTrue($this->resolver->isHomePage());
        $this->assertFalse($this->resolver->isHomePage());
        $this->assertFalse($this->resolver->isHomePage(), 'The home action at a path of its own is not the home page.');
    }

    public function testTheHomePagesUrlIsTheStoreBaseUrl(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/');
        $this->homePageLoader->method('load')->willReturn($this->page('home'));

        $this->assertSame('https://example.com/', $this->resolver->currentUrl());
    }

    public function testAnotherPagesUrlIsTheBaseUrlAndItsIdentifier(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/about-us');
        $this->getPageByIdentifier->method('execute')->willReturn($this->page('about-us'));

        $this->assertSame('https://example.com/about-us', $this->resolver->currentUrl());
    }

    public function testThereIsNoUrlWithoutACmsPage(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/missing');
        $this->getPageByIdentifier->method('execute')->willThrowException(new NoSuchEntityException(__('not found')));

        $this->assertSame('', $this->resolver->currentUrl());
    }

    /**
     * The sitemap's home page: the given store view's, not the current store's, and no request.
     */
    public function testResolveHomeIsTheGivenStoreViewsHomePage(): void
    {
        $page = $this->createStub(PageInterface::class);
        $homePageLoader = $this->createMock(HomePageLoader::class);
        $homePageLoader->expects($this->once())->method('load')->with(3)->willReturn($page);
        $request = $this->createMock(Http::class);
        $request->expects($this->never())->method('getPathInfo');

        $this->assertSame($page, $this->resolver(request: $request, homePageLoader: $homePageLoader)->resolveHome(3));
    }

    public function testResolveHomeIsNullWhenTheHomePageDoesNotLoad(): void
    {
        $this->homePageLoader->method('load')->willReturn(null);

        $this->assertNull($this->resolver->resolveHome(3));
    }

    /**
     * The resolver under test, over the given collaborators or this test's stubs.
     *
     * @param PageRepositoryInterface|null $pageRepository
     * @param Http|null $request
     * @param GetPageByIdentifierInterface|null $getPageByIdentifier
     * @param HomePageLoader|null $homePageLoader
     * @return CmsPageResolver
     */
    private function resolver(
        ?PageRepositoryInterface $pageRepository = null,
        ?Http $request = null,
        ?GetPageByIdentifierInterface $getPageByIdentifier = null,
        ?HomePageLoader $homePageLoader = null
    ): CmsPageResolver {
        return new CmsPageResolver(
            $pageRepository ?? $this->pageRepository,
            $request ?? $this->request,
            $getPageByIdentifier ?? $this->getPageByIdentifier,
            $this->storeManager,
            $homePageLoader ?? $this->homePageLoader
        );
    }

    /**
     * @param string $identifier
     * @return PageInterface
     */
    private function page(string $identifier): PageInterface
    {
        $page = $this->createStub(PageInterface::class);
        $page->method('getIdentifier')->willReturn($identifier);

        return $page;
    }
}
