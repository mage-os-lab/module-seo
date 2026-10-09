<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config\Source\XDefaultStoreViews;
use PHPUnit\Framework\TestCase;

/**
 * Issue #35: x-default can be left unset, and only an active store view can be it.
 */
class XDefaultStoreViewsTest extends TestCase
{
    /**
     * Store IDs are strings as the database gives them; the options carry them as integers.
     *
     * @return void
     */
    public function testNoneComesFirstThenTheActiveStoreViews(): void
    {
        $options = $this->source([
            $this->store('1', 'Default Store View', 'default', true),
            $this->store('2', 'German', 'de', false),
            $this->store('3', 'French', 'fr', true),
        ])->toOptionArray();

        $this->assertSame(['', 1, 3], array_column($options, 'value'));
        $this->assertSame('-- None --', (string) $options[0]['label']);
        $this->assertSame('Default Store View (default)', (string) $options[1]['label']);
    }

    public function testWithoutStoreViewsThereIsNone(): void
    {
        $this->assertSame([''], array_column($this->source([])->toOptionArray(), 'value'));
    }

    /**
     * @param Store[] $stores
     * @return XDefaultStoreViews
     */
    private function source(array $stores): XDefaultStoreViews
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return new XDefaultStoreViews($storeManager);
    }

    /**
     * @param string $id
     * @param string $name
     * @param string $code
     * @param bool $active
     * @return Store
     */
    private function store(string $id, string $name, string $code, bool $active): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getName')->willReturn($name);
        $store->method('getCode')->willReturn($code);
        $store->method('getIsActive')->willReturn($active);

        return $store;
    }
}
