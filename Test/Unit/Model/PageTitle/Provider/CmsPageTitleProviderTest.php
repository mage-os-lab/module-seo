<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageTitle\Provider;

use Magento\Cms\Api\Data\PageInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\PageTitle\Provider\CmsPageTitleProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CmsPageTitleProviderTest extends TestCase
{
    /**
     * @var CmsPageResolver&Stub
     */
    private CmsPageResolver&Stub $cmsPageResolver;

    /**
     * @var CmsPageTitleProvider
     */
    private CmsPageTitleProvider $provider;

    protected function setUp(): void
    {
        $this->cmsPageResolver = $this->createStub(CmsPageResolver::class);
        $this->provider        = new CmsPageTitleProvider($this->cmsPageResolver);
    }

    public function testGetHandlesTargetsCmsPageView(): void
    {
        $this->assertSame(['cms_page_view'], $this->provider->getHandles());
    }

    public function testReturnsPageMetaTitle(): void
    {
        $page = $this->createStub(PageInterface::class);
        $page->method('getMetaTitle')->willReturn('About Us | Meta');
        $this->cmsPageResolver->method('resolve')->willReturn($page);

        $this->assertSame('About Us | Meta', $this->provider->getTitle());
    }

    public function testEmptyWhenNoPageResolved(): void
    {
        $this->cmsPageResolver->method('resolve')->willReturn(null);
        $this->assertSame('', $this->provider->getTitle());
    }

    public function testEmptyWhenResolverThrows(): void
    {
        $this->cmsPageResolver->method('resolve')->willThrowException(new \RuntimeException('boom'));
        $this->assertSame('', $this->provider->getTitle());
    }
}
