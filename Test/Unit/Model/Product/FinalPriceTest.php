<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use MageOS\Seo\Model\Product\FinalPrice;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When a product's price is known, and so may be published.
 */
class FinalPriceTest extends TestCase
{
    public function testAPricedProductHasItsFinalPrice(): void
    {
        $this->assertSame(12.5, $this->finalPrice()->get($this->product(12.5, false)));
    }

    public function testASimpleProductPricedZeroIsFree(): void
    {
        $this->assertSame(0.0, $this->finalPrice()->get($this->product(0.0, false)));
    }

    /**
     * Core prices a composite product from its options and casts "no option to price it" to 0: an
     * out-of-stock configurable has no saleable child.
     */
    public function testACompositeProductPricedZeroHasNoKnownPrice(): void
    {
        $this->assertNull($this->finalPrice()->get($this->product(0.0, true)));
    }

    public function testACompositeProductWithAPriceHasIt(): void
    {
        $this->assertSame(15.0, $this->finalPrice()->get($this->product(15.0, true)));
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

        $this->assertNull((new FinalPrice($logger))->get($product));
    }

    /**
     * @return FinalPrice
     */
    private function finalPrice(): FinalPrice
    {
        return new FinalPrice($this->createStub(LoggerInterface::class));
    }

    /**
     * A product with the given final price.
     *
     * @param float $finalPrice
     * @param bool $composite Configurable, grouped or bundle
     * @return Product
     */
    private function product(float $finalPrice, bool $composite): Product
    {
        $price = $this->createStub(PriceInterface::class);
        $price->method('getValue')->willReturn($finalPrice);
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
