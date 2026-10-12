<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\FinalPrice;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When a product's price is known, and so may be published, and which amount that is.
 */
class FinalPriceTest extends TestCase
{
    public function testThePriceIsTheAmountTheStoreDisplays(): void
    {
        // Issue #40: prices entered excluding tax, shown including 20%: the page says 340.00, and
        // so must the offer. The amount carries the adjustments the store displays.
        $this->assertSame(340.0, $this->finalPrice()->get($this->product(283.33, 340.0)));
    }

    public function testAPricedProductHasItsFinalPrice(): void
    {
        $this->assertSame(12.5, $this->finalPrice()->get($this->product(12.5)));
    }

    public function testASimpleProductPricedZeroHasNoKnownPrice(): void
    {
        // Issue #41: usually the price is hidden from this visitor (B2B, trade), not free, and
        // Google's merchant listings refuse a price of 0.
        $this->assertNull($this->finalPrice()->get($this->product(0.0)));
    }

    public function testASimpleProductPricedZeroIsFreeWhenZeroPricesArePublished(): void
    {
        $this->assertSame(0.0, $this->finalPrice(publishZero: true)->get($this->product(0.0)));
    }

    /**
     * Core prices a composite product from its options and casts "no option to price it" to 0: an
     * out-of-stock configurable has no saleable child.
     */
    public function testACompositeProductPricedZeroHasNoKnownPriceWhateverTheSetting(): void
    {
        $this->assertNull($this->finalPrice(publishZero: true)->get($this->product(0.0, composite: true)));
    }

    public function testANegativePriceIsNeverKnown(): void
    {
        $this->assertNull($this->finalPrice(publishZero: true)->get($this->product(-1.0)));
    }

    public function testACompositeProductWithAPriceHasIt(): void
    {
        $this->assertSame(15.0, $this->finalPrice()->get($this->product(15.0, composite: true)));
    }

    public function testALookupThatThrowsIsLoggedWithTheSkuAndGivesNoPrice(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('TEE-M');
        $product->method('getPriceInfo')->willThrowException(new \RuntimeException('No price index'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->logicalAnd($this->stringContains('"TEE-M"'), $this->stringContains('No price index'))
        );

        $this->assertNull($this->finalPrice(logger: $logger)->get($product));
    }

    /**
     * @param bool $publishZero Publish a Price of Zero
     * @param LoggerInterface|null $logger
     * @return FinalPrice
     */
    private function finalPrice(bool $publishZero = false, ?LoggerInterface $logger = null): FinalPrice
    {
        $config = $this->createStub(Config::class);
        $config->method('isZeroPricePublished')->willReturn($publishZero);

        return new FinalPrice($logger ?? $this->createStub(LoggerInterface::class), $config);
    }

    /**
     * A product whose final price has the given value, and the given amount as the store displays
     * it (the value again when not given).
     *
     * @param float $value Before display adjustments (getValue())
     * @param float|null $amount After them (getAmount()->getValue())
     * @param bool $composite Configurable, grouped or bundle
     * @return Product
     */
    private function product(float $value, ?float $amount = null, bool $composite = false): Product
    {
        $displayed = $this->createStub(AmountInterface::class);
        $displayed->method('getValue')->willReturn($amount ?? $value);
        $price = $this->createStub(PriceInterface::class);
        $price->method('getValue')->willReturn($value);
        $price->method('getAmount')->willReturn($displayed);
        $priceInfo = $this->createStub(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);
        $type = $this->createStub(AbstractType::class);
        $type->method('isComposite')->willReturn($composite);

        $product = $this->createStub(Product::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getTypeInstance')->willReturn($type);

        return $product;
    }
}
