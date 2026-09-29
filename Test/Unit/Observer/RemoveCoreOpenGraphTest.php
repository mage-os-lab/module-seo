<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\LayoutInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Observer\RemoveCoreOpenGraph;
use PHPUnit\Framework\TestCase;

class RemoveCoreOpenGraphTest extends TestCase
{
    public function testCoresBlockIsRemovedWhileThisModuleEmitsOpenGraph(): void
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('hasElement')->with('opengraph.general')->willReturn(true);
        $layout->expects($this->once())->method('unsetElement')->with('opengraph.general');

        $this->observer(true)->execute($this->event($layout));
    }

    public function testCoresBlockStaysWhenThisModulesOpenGraphIsOff(): void
    {
        // Otherwise switching this module's Open Graph off would leave product pages with none.
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('hasElement')->willReturn(true);
        $layout->expects($this->never())->method('unsetElement');

        $this->observer(false)->execute($this->event($layout));
    }

    public function testPagesWithoutCoresBlockAreLeftAlone(): void
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('hasElement')->willReturn(false);
        $layout->expects($this->never())->method('unsetElement');

        $this->observer(true)->execute($this->event($layout));
    }

    /**
     * @param bool $ogEnabled
     * @return RemoveCoreOpenGraph
     */
    private function observer(bool $ogEnabled): RemoveCoreOpenGraph
    {
        $config = $this->createStub(Config::class);
        $config->method('isOgTagsEnabled')->willReturn($ogEnabled);

        return new RemoveCoreOpenGraph($config);
    }

    /**
     * @param LayoutInterface $layout
     * @return Observer
     */
    private function event(LayoutInterface $layout): Observer
    {
        $event = new Event(['layout' => $layout]);

        return new Observer(['event' => $event]);
    }
}
