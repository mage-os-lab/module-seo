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
use PHPUnit\Framework\MockObject\MockObject;
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
     * @var PageRepositoryInterface&MockObject
     */
    private PageRepositoryInterface&MockObject $pageRepository;

    /**
     * @var Http&MockObject
     */
    private Http&MockObject $request;

    /**
     * @var GetPageByIdentifierInterface&MockObject
     */
    private GetPageByIdentifierInterface&MockObject $getPageByIdentifier;

    /**
     * @var HomePageLoader&MockObject
     */
    private HomePageLoader&MockObject $homePageLoader;

    /**
     * @var CmsPageResolver
     */
    private CmsPageResolver $resolver;

    protected function setUp(): void
    {
        $this->pageRepository      = $this->createMock(PageRepositoryInterface::class);
        $this->request             = $this->createMock(Http::class);
        $this->getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $this->homePageLoader      = $this->createMock(HomePageLoader::class);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->resolver = new CmsPageResolver(
            $this->pageRepository,
            $this->request,
            $this->getPageByIdentifier,
            $storeManager,
            $this->homePageLoader
        );
    }

    public function testPageIdParamLoadsByIdWithoutTheIdentifierService(): void
    {
        $this->request->method('getParam')->with('page_id')->willReturn(5);
        $page = $this->createStub(PageInterface::class);
        $this->pageRepository->method('getById')->with(5)->willReturn($page);
        $this->getPageByIdentifier->expects($this->never())->method('execute');

        $this->assertSame($page, $this->resolver->resolve());
    }

    public function testPathInfoIdentifierResolvesViaTheService(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/about-us');
        $page = $this->createStub(PageInterface::class);
        $this->getPageByIdentifier->method('execute')->with('about-us', 1)->willReturn($page);

        $this->assertSame($page, $this->resolver->resolve());
    }

    public function testAnEmptyPathResolvesTheCurrentStoreViewsHomePage(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/');
        $page = $this->createStub(PageInterface::class);
        $this->homePageLoader->expects($this->once())->method('load')->with(1)->willReturn($page);
        $this->getPageByIdentifier->expects($this->never())->method('execute');

        $this->assertSame($page, $this->resolver->resolve());
    }

    public function testMissingPageResolvesToNullAndIsMemoised(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/missing');
        $this->getPageByIdentifier->expects($this->once())->method('execute')
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $this->assertNull($this->resolver->resolve());
        // Second call must not hit the service again (the null result is memoised).
        $this->assertNull($this->resolver->resolve());
    }

    public function testResetStateForcesAFreshResolution(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/about-us');
        $page = $this->createStub(PageInterface::class);
        $this->getPageByIdentifier->expects($this->exactly(2))->method('execute')->willReturn($page);

        $this->resolver->resolve();
        $this->resolver->_resetState();
        $this->resolver->resolve();
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
        $this->homePageLoader->expects($this->once())->method('load')->with(3)->willReturn($page);
        $this->request->expects($this->never())->method('getPathInfo');

        $this->assertSame($page, $this->resolver->resolveHome(3));
    }

    public function testResolveHomeIsNullWhenTheHomePageDoesNotLoad(): void
    {
        $this->homePageLoader->method('load')->willReturn(null);

        $this->assertNull($this->resolver->resolveHome(3));
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
