<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use MageOS\Seo\Model\PageTitle\Compositor;
use MageOS\Seo\Observer\ApplyPageTitle;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ApplyPageTitleTest extends TestCase
{
    /**
     * @var Compositor&Stub
     */
    private Compositor&Stub $compositor;

    /**
     * @var PageConfig&Stub
     */
    private PageConfig&Stub $pageConfig;

    /**
     * @var ApplyPageTitle
     */
    private ApplyPageTitle $observer;

    protected function setUp(): void
    {
        $this->compositor = $this->createStub(Compositor::class);
        $this->pageConfig = $this->createStub(PageConfig::class);
        $this->observer   = $this->observer();
    }

    /**
     * The observer under test, over the given page config or this test's stub.
     *
     * @param PageConfig|null $pageConfig
     * @return ApplyPageTitle
     */
    private function observer(?PageConfig $pageConfig = null): ApplyPageTitle
    {
        return new ApplyPageTitle($this->compositor, $pageConfig ?? $this->pageConfig);
    }

    public function testSetsTitleWhenCompositorReturnsNonEmpty(): void
    {
        $this->compositor->method('getTitle')->willReturn('Winning Title');

        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('set')->with('Winning Title');
        $this->pageConfig->method('getTitle')->willReturn($title);

        $this->observer->execute($this->createStub(Observer::class));
    }

    public function testDoesNothingWhenCompositorReturnsEmpty(): void
    {
        // Core behaviour must be untouched when no provider supplied a title.
        $this->compositor->method('getTitle')->willReturn('');
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->expects($this->never())->method('getTitle');

        $this->observer($pageConfig)->execute($this->createStub(Observer::class));
    }

    public function testSwallowsExceptionsSoRenderingNeverBreaks(): void
    {
        $this->compositor->method('getTitle')->willThrowException(new \RuntimeException('boom'));
        // getTitle() is never reached, and the exception must not propagate out of execute().
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->expects($this->never())->method('getTitle');

        $this->observer($pageConfig)->execute($this->createStub(Observer::class));
    }
}
