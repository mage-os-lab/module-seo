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
use MageOS\Seo\Model\Product\Builder\LocalExperienceBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Test\Unit\Service\CurrencyServices;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class LocalExperienceBuilderTest extends TestCase
{
    use CurrencyServices;
    use OfferBuilders;

    /**
     * @var Product&Stub
     */
    private Product&Stub $product;

    /**
     * @var LocalExperienceBuilder
     */
    private LocalExperienceBuilder $builder;

    protected function setUp(): void
    {
        $storeManager    = $this->createStub(StoreManagerInterface::class);
        $store           = $this->createStub(Store::class);
        $currencyService = $this->currencyService();
        $imageHelper     = $this->createStub(ImageHelper::class);
        $seoConfig       = $this->createStub(Config::class);
        $priceInfo       = $this->createMock(PriceInfoInterface::class);
        $finalPrice      = $this->createStub(PriceInterface::class);
        $availability    = $this->createStub(AvailabilityResolver::class);

        $this->product = $this->createStub(Product::class);

        $storeManager->method('getStore')->willReturn($store);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $finalPrice->method('getValue')->willReturn(35.00);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Pottery Workshop');
        $this->product->method('getSku')->willReturn('EXP-01');
        $this->product->method('getId')->willReturn(26);
        $this->product->method('getProductUrl')->willReturn('https://example.com/pottery');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new LocalExperienceBuilder(
            $storeManager,
            $imageHelper,
            $seoConfig,
            $this->offerBuilder(
                $storeManager,
                $currencyService,
                $availability,
                $this->createStub(DateTime::class),
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
        $this->assertSame('LocalExperience', $this->builder->getTemplateCode());
    }

    public function testBuildReturnsPlainProductType(): void
    {
        // Experiences with real schedules belong in a dedicated Event node; on the
        // Product node, Event-only properties are additionalProperty entries.
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Product', $schema['@type']);
    }

    public function testLocationBecomesAdditionalProperty(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'location' ? 'Studio 5, Bristol' : null
        );
        $schema = $this->builder->build($this->product, ['location'], []);

        $this->assertArrayNotHasKey('location', $schema);
        $entry = $this->findAdditionalProperty($schema, 'location');
        $this->assertNotNull($entry);
        $this->assertSame('Studio 5, Bristol', $entry['value']);
    }

    public function testDurationBecomesAdditionalProperty(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'duration' ? '2 hours' : null
        );
        $schema = $this->builder->build($this->product, ['duration'], []);

        $entry = $this->findAdditionalProperty($schema, 'duration');
        $this->assertNotNull($entry);
        $this->assertSame('2 hours', $entry['value']);
    }

    public function testOrganizerFromOverrideBecomesAdditionalProperty(): void
    {
        $schema = $this->builder->build($this->product, ['organizer'], ['organizer' => 'Bristol Crafts']);

        $entry = $this->findAdditionalProperty($schema, 'organizer');
        $this->assertNotNull($entry);
        $this->assertSame('Bristol Crafts', $entry['value']);
        $this->assertArrayNotHasKey('organizer', $schema, 'organizer is not a Product property.');
    }

    public function testAvailabilityStartsGoesOnTheOfferNode(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'availability_starts' ? '2026-08-01' : null
        );
        $schema = $this->builder->build($this->product, ['availabilityStarts'], []);
        $this->assertSame('2026-08-01', $schema['offers']['availabilityStarts']);
    }
}
