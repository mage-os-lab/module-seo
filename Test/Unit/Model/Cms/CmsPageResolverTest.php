<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
     * @var ScopeConfigInterface&MockObject
     */
    private ScopeConfigInterface&MockObject $scopeConfig;

    /**
     * @var CmsPageResolver
     */
    private CmsPageResolver $resolver;

    protected function setUp(): void
    {
        $this->pageRepository      = $this->createMock(PageRepositoryInterface::class);
        $this->request             = $this->createMock(Http::class);
        $this->getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $this->scopeConfig         = $this->createMock(ScopeConfigInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->resolver = new CmsPageResolver(
            $this->pageRepository,
            $this->request,
            $this->getPageByIdentifier,
            $storeManager,
            $this->scopeConfig
        );
    }

    public function testPageIdParamLoadsByIdWithoutTheIdentifierService(): void
    {
        $this->request->method('getParam')->with('page_id')->willReturn(5);
        $page = $this->createMock(PageInterface::class);
        $this->pageRepository->method('getById')->with(5)->willReturn($page);
        $this->getPageByIdentifier->expects($this->never())->method('execute');

        $this->assertSame($page, $this->resolver->resolve());
    }

    public function testPathInfoIdentifierResolvesViaTheService(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/about-us');
        $page = $this->createMock(PageInterface::class);
        $this->getPageByIdentifier->method('execute')->with('about-us', 1)->willReturn($page);

        $this->assertSame($page, $this->resolver->resolve());
    }

    public function testEmptyPathUsesHomeIdentifierWithLayoutSuffixStripped(): void
    {
        $this->request->method('getParam')->willReturn(0);
        $this->request->method('getPathInfo')->willReturn('/');
        $this->scopeConfig->method('getValue')->willReturn('home|2columns-left');
        $page = $this->createMock(PageInterface::class);
        $this->getPageByIdentifier->method('execute')->with('home', 1)->willReturn($page);

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
        $page = $this->createMock(PageInterface::class);
        $this->getPageByIdentifier->expects($this->exactly(2))->method('execute')->willReturn($page);

        $this->resolver->resolve();
        $this->resolver->_resetState();
        $this->resolver->resolve();
    }

    /**
     * The sitemap's home page: the given store view's configured page, not the current store's.
     */
    public function testResolveHomeReadsTheGivenStoreViewsHomePage(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(\Magento\Cms\Helper\Page::XML_PATH_HOME_PAGE, 'store', 3)
            ->willReturn('startseite|1column');
        $page = $this->createMock(PageInterface::class);
        $this->getPageByIdentifier->method('execute')->with('startseite', 3)->willReturn($page);
        $this->request->expects($this->never())->method('getPathInfo');

        $this->assertSame($page, $this->resolver->resolveHome(3));
    }

    public function testResolveHomeIsNullWhenThePageDoesNotResolve(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('home');
        $this->getPageByIdentifier->method('execute')
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $this->assertNull($this->resolver->resolveHome(3));
    }

    public function testResolveHomeIsNullWhenNoHomePageIsConfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->getPageByIdentifier->expects($this->never())->method('execute');

        $this->assertNull($this->resolver->resolveHome(3));
    }
}
