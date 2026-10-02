<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Block;

use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\FaqCollectorInterface;
use MageOS\Seo\Block\Widget\FaqList;
use MageOS\Seo\Model\Faq\SourcePool;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the AbstractFaqElement resolve/collect flow through the concrete FaqList widget.
 */
class FaqListTest extends TestCase
{
    /**
     * @var SourcePool&Stub
     */
    private SourcePool&Stub $sourcePool;

    /**
     * @var FaqCollectorInterface&Stub
     */
    private FaqCollectorInterface&Stub $collector;

    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    protected function setUp(): void
    {
        $this->sourcePool = $this->createStub(SourcePool::class);
        $this->collector  = $this->createStub(FaqCollectorInterface::class);

        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $store              = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    /**
     * The widget under test, over the given doubles or this test's stubs.
     *
     * @param SourcePool|null $sourcePool
     * @param FaqCollectorInterface|null $collector
     * @return FaqList
     */
    private function block(?SourcePool $sourcePool = null, ?FaqCollectorInterface $collector = null): FaqList
    {
        return new FaqList(
            $this->createStub(Context::class),
            $sourcePool ?? $this->sourcePool,
            $collector ?? $this->collector,
            $this->storeManager
        );
    }

    public function testResolvesFaqsAndCollectsIdentifier(): void
    {
        $faqs = [['question' => 'Q', 'answer' => 'A']];
        $collector = $this->createMock(FaqCollectorInterface::class);
        $collector->expects($this->once())->method('collect')->with('shipping');
        $sourcePool = $this->createMock(SourcePool::class);
        $sourcePool->method('getFaqs')->with('shipping', 1)->willReturn($faqs);
        $block = $this->block($sourcePool, $collector);
        $block->setData('identifier', 'shipping');

        $this->assertSame($faqs, $block->getFaqs());
    }

    public function testEmptyIdentifierReturnsEmptyAndDoesNotCollect(): void
    {
        $collector = $this->createMock(FaqCollectorInterface::class);
        $collector->expects($this->never())->method('collect');
        $block = $this->block(collector: $collector);
        $block->setData('identifier', '');
        $this->assertSame([], $block->getFaqs());
    }

    public function testResolutionIsMemoised(): void
    {
        $sourcePool = $this->createMock(SourcePool::class);
        $sourcePool->expects($this->once())->method('getFaqs')->willReturn([
            ['question' => 'Q', 'answer' => 'A'],
        ]);
        $block = $this->block($sourcePool);
        $block->setData('identifier', 'shipping');
        $block->getFaqs();
        $block->getFaqs();
    }
}
