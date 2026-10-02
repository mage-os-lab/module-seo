<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config\Source\StoreViews;
use PHPUnit\Framework\TestCase;

class StoreViewsTest extends TestCase
{
    public function testReturnsActiveStoresAsOptions(): void
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $store->method('getName')->willReturn('UK Store');
        $store->method('getCode')->willReturn('uk');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);

        $options = (new StoreViews($storeManager))->toOptionArray();

        $this->assertCount(1, $options);
        $this->assertSame(2, $options[0]['value']);
        $this->assertStringContainsString('UK Store', $options[0]['label']);
        $this->assertStringContainsString('uk', $options[0]['label']);
    }

    public function testReturnsEmptyWhenNoStores(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $this->assertSame([], (new StoreViews($storeManager))->toOptionArray());
    }
}
