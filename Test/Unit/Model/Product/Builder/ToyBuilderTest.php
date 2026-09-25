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
use MageOS\Seo\Model\Product\Builder\ToyBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ToyBuilderTest extends TestCase
{
    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var ToyBuilder
     */
    private ToyBuilder $builder;

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
        $finalPrice->method('getValue')->willReturn(19.99);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Building Blocks');
        $this->product->method('getSku')->willReturn('TOY-01');
        $this->product->method('getId')->willReturn(25);
        $this->product->method('getProductUrl')->willReturn('https://example.com/blocks');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new ToyBuilder(
            $storeManager,
            $currencyService,
            $availability,
            $imageHelper,
            $seoConfig,
            $this->createMock(DateTime::class),
            new OfferEnricherPool(),
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
        $this->assertSame('Toy', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Toy & Game', $this->builder->getLabel());
    }

    public function testBatteriesRequiredBecomesYesNoAdditionalProperty(): void
    {
        // batteriesRequired is not a schema.org Product property.
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'batteries_required' ? 'yes' : null
        );
        $schema = $this->builder->build($this->product, ['batteriesRequired'], []);

        $this->assertArrayNotHasKey('batteriesRequired', $schema);
        $entry = $this->findAdditionalProperty($schema, 'batteriesRequired');
        $this->assertNotNull($entry);
        $this->assertSame('Yes', $entry['value']);
    }

    public function testWarningBecomesSafetyWarningAdditionalProperty(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'safety_warning' ? 'Choking hazard' : null
        );
        $schema = $this->builder->build($this->product, ['warning'], []);

        $entry = $this->findAdditionalProperty($schema, 'safetyWarning');
        $this->assertNotNull($entry);
        $this->assertSame('Choking hazard', $entry['value']);
    }

    public function testSuggestedAgeBecomesPeopleAudienceNode(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'min_age' ? '3' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['suggestedAge'], []);

        $this->assertSame('PeopleAudience', $schema['audience']['@type']);
        $this->assertSame(3.0, $schema['audience']['suggestedMinAge']);
    }

    public function testMaterialAndColorAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'material' => 'Plastic',
                'color'    => 'Red',
                default    => null,
            }
        );
        $schema = $this->builder->build($this->product, ['material', 'color'], []);
        $this->assertSame('Plastic', $schema['material']);
        $this->assertSame('Red', $schema['color']);
    }
}
