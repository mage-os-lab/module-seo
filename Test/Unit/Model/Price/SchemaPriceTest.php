<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Price;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Price\SchemaPrice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A price as structured data and the feeds publish it: an amount with two decimals and a point, no
 * grouping, in the store view's current currency (review low item: CurrencyService replaced).
 */
class SchemaPriceTest extends TestCase
{
    /**
     * @dataProvider amounts
     * @param float $amount
     * @param string $expected
     * @return void
     */
    #[DataProvider('amounts')]
    public function testAnAmountHasTwoDecimalsAPointAndNoGrouping(float $amount, string $expected): void
    {
        $this->assertSame($expected, $this->schemaPrice('GBP')->amount($amount));
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function amounts(): array
    {
        return [
            'whole'        => [52.0, '52.00'],
            'one decimal'  => [19.5, '19.50'],
            'rounded'      => [1234.567, '1234.57'],
            'zero'         => [0.0, '0.00'],
            'large'        => [1234567.8, '1234567.80'],
        ];
    }

    /**
     * @return void
     */
    public function testTheCurrencyIsTheStoreViewsCurrentOne(): void
    {
        $this->assertSame('EUR', $this->schemaPrice('EUR')->currencyCode());
    }

    /**
     * A store that cannot be read is not answered with a guessed currency.
     *
     * @return void
     */
    public function testAStoreThatCannotBeReadIsNotHiddenBehindAGuess(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $this->expectException(\RuntimeException::class);
        (new SchemaPrice($storeManager))->currencyCode();
    }

    /**
     * The class over a store whose current currency is the given one.
     *
     * @param string $currencyCode
     * @return SchemaPrice
     */
    private function schemaPrice(string $currencyCode): SchemaPrice
    {
        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn($currencyCode);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new SchemaPrice($storeManager);
    }
}
