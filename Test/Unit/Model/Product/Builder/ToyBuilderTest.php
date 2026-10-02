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
use MageOS\Seo\Test\Unit\Service\CurrencyServices;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ToyBuilderTest extends TestCase
{
    use CurrencyServices;
    use OfferBuilders;

    /**
     * @var Product&Stub
     */
    private Product&Stub $product;

    /**
     * @var ToyBuilder
     */
    private ToyBuilder $builder;

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
        $finalPrice->method('getValue')->willReturn(19.99);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Building Blocks');
        $this->product->method('getSku')->willReturn('TOY-01');
        $this->product->method('getId')->willReturn(25);
        $this->product->method('getProductUrl')->willReturn('https://example.com/blocks');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new ToyBuilder(
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

    public function testOverridesLandWhereTheTemplatePutsThemAndNowhereElse(): void
    {
        // An override for one of the template's own fields is built in the template's shape; it is
        // not written again as a top-level property, which Product does not have.
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build(
            $this->product,
            ['warning', 'batteriesRequired', 'suggestedAge'],
            ['warning' => 'Small parts', 'batteriesRequired' => 'no', 'suggestedAge' => '5']
        );

        $this->assertSame('Small parts', $this->findAdditionalProperty($schema, 'safetyWarning')['value'] ?? null);
        $this->assertSame('No', $this->findAdditionalProperty($schema, 'batteriesRequired')['value'] ?? null);
        $this->assertSame(5.0, $schema['audience']['suggestedMinAge'] ?? null);
        $this->assertArrayNotHasKey('warning', $schema);
        $this->assertArrayNotHasKey('batteriesRequired', $schema);
        $this->assertArrayNotHasKey('suggestedAge', $schema);
    }

    public function testPlayerCountBecomesAdditionalProperty(): void
    {
        // Product has no player-count property.
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'player_count' ? '2-4' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['playerCount'], []);

        $this->assertSame('2-4', $this->findAdditionalProperty($schema, 'playerCount')['value'] ?? null);
        $this->assertArrayNotHasKey('playerCount', $schema);
    }

    public function testPlayerCountFromOverride(): void
    {
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['playerCount'], ['playerCount' => '1-6']);

        $this->assertSame('1-6', $this->findAdditionalProperty($schema, 'playerCount')['value'] ?? null);
        $this->assertArrayNotHasKey('playerCount', $schema);
    }

    public function testABrandOverrideIsABrandNode(): void
    {
        // Brand is one of the template's own fields, so applyOverrides() must not replace the node
        // the template builds from the override with the raw string.
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['brand'], ['brand' => 'Blockworks']);

        $this->assertSame(['@type' => 'Brand', 'name' => 'Blockworks'], $schema['brand']);
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
