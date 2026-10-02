<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Seo\Model\Product\OfferEnricher\ItemConditionEnricher;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ItemConditionEnricherTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&Stub
     */
    private ScopeConfigInterface&Stub $scopeConfig;

    /**
     * @var ProductInterface&Stub
     */
    private ProductInterface&Stub $product;

    /**
     * @var ItemConditionEnricher
     */
    private ItemConditionEnricher $enricher;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->product     = $this->createStub(ProductInterface::class);
        $this->enricher    = new ItemConditionEnricher($this->scopeConfig);
    }

    public function testSortOrderIsHundred(): void
    {
        $this->assertSame(100, $this->enricher->getSortOrder());
    }

    public function testReturnsItemConditionFromConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('https://schema.org/UsedCondition');
        $this->assertSame(
            ['itemCondition' => 'https://schema.org/UsedCondition'],
            $this->enricher->enrich($this->product, 1)
        );
    }

    public function testReturnsEmptyWhenNotConfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->assertSame([], $this->enricher->enrich($this->product, 1));
    }
}
