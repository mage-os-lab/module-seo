<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\FinalPrice;
use MageOS\Seo\Model\Product\OfferSummary;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Test\Unit\Model\Product\Variant\ChildProductsOverProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a product's offer says, as numbers: its price, a configurable's price range across its
 * sellable children, and its availability, a configurable's from its best child.
 *
 * The product page's OfferBuilder and MageOS_Aeo's llms.jsonl both read it (review M4: a
 * configurable whose children differ in price was a single Offer in the feed, and took its
 * availability from its own index row, while its page had an AggregateOffer and its best child's
 * availability).
 */
class OfferSummaryTest extends TestCase
{
    use ChildProductsOverProvider;

    /**
     * @return void
     */
    public function testASimpleProductHasItsPriceAndAvailability(): void
    {
        $this->assertSame(
            [
                'price'        => 12.5,
                'lowPrice'     => null,
                'highPrice'    => null,
                'availability' => AvailabilityResolver::IN_STOCK,
            ],
            $this->summary()->forProduct($this->product('simple', 12.5))
        );
    }

    /**
     * @return void
     */
    public function testAConfigurableWhoseChildrenDifferInPriceHasARange(): void
    {
        $children = [$this->product('simple', 40.0), $this->product('simple', 52.0)];

        $this->assertSame(
            [
                'price'        => 40.0,
                'lowPrice'     => 40.0,
                'highPrice'    => 52.0,
                'availability' => AvailabilityResolver::BACKORDER,
            ],
            $this->summary($children, best: AvailabilityResolver::BACKORDER)
                ->forProduct($this->product('configurable', 40.0))
        );
    }

    /**
     * @return void
     */
    public function testAConfigurableWhoseChildrenShareAPriceHasNoRange(): void
    {
        $children = [$this->product('simple', 52.0), $this->product('simple', 52.0)];

        $summary = $this->summary($children)->forProduct($this->product('configurable', 52.0));

        $this->assertSame(52.0, $summary['price']);
        $this->assertNull($summary['lowPrice']);
        $this->assertNull($summary['highPrice']);
    }

    /**
     * An unknown price is null, never 0, which reads as free.
     *
     * @return void
     */
    public function testAnUnknownPriceIsNull(): void
    {
        $this->assertNull($this->summary()->forProduct($this->product('configurable', 0.0))['price']);
    }

    /**
     * Decision D8a: a stock lookup that failed is unknown, not OutOfStock.
     *
     * @return void
     */
    public function testAnUnknownAvailabilityIsNull(): void
    {
        $this->assertNull($this->summary(resolve: null)->forProduct($this->product('simple', 12.5))['availability']);
    }

    /**
     * The summary over a configurable's children and the stock answers given.
     *
     * @param Product[] $children
     * @param string|null $resolve What resolve() answers for a product without children
     * @param string|null $best What best() answers for a configurable's children
     * @return OfferSummary
     */
    private function summary(
        array $children = [],
        ?string $resolve = AvailabilityResolver::IN_STOCK,
        ?string $best = AvailabilityResolver::IN_STOCK
    ): OfferSummary {
        $optionsProvider = $this->createStub(ConfigurableOptionsProviderInterface::class);
        $optionsProvider->method('getProducts')->willReturn($children);
        $availability = $this->createStub(AvailabilityResolver::class);
        $availability->method('resolve')->willReturn($resolve);
        $availability->method('best')->willReturn($best);

        return new OfferSummary(
            new FinalPrice($this->createStub(LoggerInterface::class), $this->createStub(Config::class)),
            $this->childProductsOver($optionsProvider),
            $availability
        );
    }

    /**
     * A feed reads a page's variants at once, then their stock in one lookup (PLAN-G, "G2.3
     * follow-up"), so each line's summary is read from what is already loaded.
     *
     * @return void
     */
    public function testPrefetchReadsThePagesVariantsThenTheirStockOnce(): void
    {
        $configurable = $this->product('configurable', 30.0);
        $simple       = $this->product('simple', 12.5);
        $children     = [$this->product('simple', 20.0), $this->product('simple', 40.0)];
        $childProducts = $this->createMock(ChildProducts::class);
        $childProducts->expects($this->once())->method('prefetch')->with([$configurable, $simple]);
        $childProducts->method('get')->willReturnCallback(
            static fn (Product $product): array => $product === $configurable ? $children : []
        );
        $availability = $this->createMock(AvailabilityResolver::class);
        $availability->expects($this->once())->method('prefetch')->with($children);

        (new OfferSummary(
            new FinalPrice($this->createStub(LoggerInterface::class), $this->createStub(Config::class)),
            $childProducts,
            $availability
        ))->prefetch([$configurable, $simple]);
    }

    /**
     * A product with a final price as the page shows it.
     *
     * @param string $typeId
     * @param float $finalPrice
     * @return Product
     */
    private function product(string $typeId, float $finalPrice): Product
    {
        $amount = $this->createStub(AmountInterface::class);
        $amount->method('getValue')->willReturn($finalPrice);
        $price = $this->createStub(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $price->method('getValue')->willReturn($finalPrice);
        $priceInfo = $this->createStub(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);
        $type = $this->createStub(AbstractType::class);
        $type->method('isComposite')->willReturn($typeId === 'configurable');

        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn($typeId);
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getSku')->willReturn($typeId . '-' . $finalPrice);

        return $product;
    }
}
