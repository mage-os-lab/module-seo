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
use MageOS\Seo\Model\Product\Builder\BookBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BookBuilderTest extends TestCase
{
    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var BookBuilder
     */
    private BookBuilder $builder;

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
        $finalPrice->method('getValue')->willReturn(12.99);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('A Novel');
        $this->product->method('getSku')->willReturn('BOOK-01');
        $this->product->method('getId')->willReturn(21);
        $this->product->method('getProductUrl')->willReturn('https://example.com/a-novel');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new BookBuilder(
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

    public function testGetTemplateCode(): void
    {
        $this->assertSame('Book', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Book', $this->builder->getLabel());
    }

    public function testBuildReturnsProductAndBookMultiType(): void
    {
        // Book alone is a CreativeWork subtype and forfeits Product rich results;
        // Product must accompany it.
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame(['Product', 'Book'], $schema['@type']);
    }

    public function testIsbnKeptTopLevelAndAlsoEmittedAsGtinWhenValid(): void
    {
        // A valid ISBN-13 is also the book's GTIN.
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'isbn' ? '5901234123457' : null
        );
        $schema = $this->builder->build($this->product, ['isbn'], []);
        $this->assertSame('5901234123457', $schema['isbn']);
        $this->assertSame('5901234123457', $schema['gtin13']);
    }

    public function testIsbn10KeptTopLevelButNotEmittedAsGtin(): void
    {
        // ISBN-10 is not a GTIN — kept as isbn, never emitted as gtin13.
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'isbn' ? '0-306-40615-2' : null
        );
        $schema = $this->builder->build($this->product, ['isbn'], []);
        $this->assertSame('0-306-40615-2', $schema['isbn']);
        $this->assertArrayNotHasKey('gtin13', $schema);
    }

    public function testAuthorBecomesPersonNode(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'author' ? 'Jane Doe' : null
        );
        $schema = $this->builder->build($this->product, ['author'], []);
        $this->assertSame('Person', $schema['author']['@type']);
        $this->assertSame('Jane Doe', $schema['author']['name']);
    }

    public function testPublisherBecomesOrganizationNode(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'publisher' ? 'Acme Press' : null
        );
        $schema = $this->builder->build($this->product, ['publisher'], []);
        $this->assertSame('Organization', $schema['publisher']['@type']);
        $this->assertSame('Acme Press', $schema['publisher']['name']);
    }

    public function testBookFormatMappedToSchemaUri(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'book_format' ? 'Hardcover' : null
        );
        $schema = $this->builder->build($this->product, ['bookFormat'], []);
        $this->assertSame('https://schema.org/Hardcover', $schema['bookFormat']);
    }
}
