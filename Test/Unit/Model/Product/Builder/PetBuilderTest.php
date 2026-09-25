<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Builder;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\Builder\PetBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PetBuilderTest extends TestCase
{
    use OfferBuilders;

    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var PetBuilder
     */
    private PetBuilder $builder;

    protected function setUp(): void
    {
        $storeManager    = $this->createMock(StoreManagerInterface::class);
        $store           = $this->createMock(Store::class);
        $currencyService = $this->createMock(CurrencyService::class);
        $imageHelper     = $this->createMock(ImageHelper::class);
        $seoConfig       = $this->createMock(Config::class);
        $priceInfo       = $this->createMock(PriceInfoInterface::class);
        $finalPrice      = $this->createMock(PriceInterface::class);
        $availability    = $this->createMock(AvailabilityResolver::class);

        $this->product = $this->createMock(Product::class);

        $storeManager->method('getStore')->willReturn($store);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $currencyService->method('convertFromBase')->willReturnArgument(0);
        $finalPrice->method('getValue')->willReturn(9.99);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Dog Chew');
        $this->product->method('getSku')->willReturn('PET-01');
        $this->product->method('getId')->willReturn(24);
        $this->product->method('getProductUrl')->willReturn('https://example.com/dog-chew');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new PetBuilder(
            $storeManager,
            $imageHelper,
            $seoConfig,
            $this->offerBuilder(
                $storeManager,
                $currencyService,
                $availability,
                $seoConfig,
                $this->createMock(DateTime::class),
                new OfferEnricherPool()
            ),
            new AggregateRatingResolver(),
            new GtinValidator()
        );
    }

    /**
     * Find an additionalProperty PropertyValue entry by name, or null.
     *
     * @param array<string, mixed> $schema
     * @param string $name
     * @return array<string, mixed>|null
     */
    private function findAdditionalProperty(array $schema, string $name): ?array
    {
        foreach ($schema['additionalProperty'] ?? [] as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }
        return null;
    }

    public function testGetTemplateCode(): void
    {
        $this->assertSame('Pet', $this->builder->getTemplateCode());
    }

    public function testBuildReturnsPlainProductType(): void
    {
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Product', $schema['@type']);
    }

    public function testTargetSpeciesBecomesAdditionalProperty(): void
    {
        // PeopleAudience is for humans; species has no valid Product property.
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'pet_species' ? 'Dog' : null
        );
        $schema = $this->builder->build($this->product, ['targetSpecies'], []);

        $this->assertArrayNotHasKey('targetSpecies', $schema);
        $entry = $this->findAdditionalProperty($schema, 'targetSpecies');
        $this->assertNotNull($entry);
        $this->assertSame('Dog', $entry['value']);
    }

    public function testWarningBecomesSafetyWarningAdditionalProperty(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'safety_warning' ? 'Not for puppies under 6 months' : null
        );
        $schema = $this->builder->build($this->product, ['warning'], []);

        $entry = $this->findAdditionalProperty($schema, 'safetyWarning');
        $this->assertNotNull($entry);
        $this->assertSame('Not for puppies under 6 months', $entry['value']);
    }

    public function testMaterialAndColorAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'ingredients' => 'Rawhide',
                'color'       => 'Brown',
                default       => null,
            }
        );
        $schema = $this->builder->build($this->product, ['material', 'color'], []);
        $this->assertSame('Rawhide', $schema['material']);
        $this->assertSame('Brown', $schema['color']);
    }

    public function testInvalidGtinFromBarcodeIsOmitted(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'barcode' ? '5901234123450' : null
        );
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertArrayNotHasKey('gtin13', $schema);
    }
}
