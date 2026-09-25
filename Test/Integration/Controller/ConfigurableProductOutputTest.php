<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Test\Integration\Fixture\ProductAttribute as AttributeFixture;

/**
 * A configurable product page's product JSON-LD.
 *
 * Within `has_variant_max` sellable children, the page describes a ProductGroup whose variants carry
 * the offers; above it, or with the setting at 0, one Product with an AggregateOffer over the
 * children's whole price range. The first test's product varies by `size` — one of the six codes
 * Google's `variesBy` accepts, reused where the install already has it — and by a second
 * attribute with a generated code, which must become an `additionalProperty` and stay out of
 * `variesBy`.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class ConfigurableProductOutputTest extends AbstractController
{
    use ProductPageOutput;

    private const SKU = 'mageos-seo-tee';

    private const NAME = 'MageOS SEO Tee';

    private const URL = 'http://localhost/index.php/' . self::SKU . '.html';

    private const PLACEHOLDER = 'http://localhost/static/VERSION/frontend/Magento/luma/en_US/Magento_Catalog/'
        . 'images/product/placeholder/image.jpg';

    /**
     * @return void
     */
    #[
        // An install with Magento's sample data already has a size attribute: reuse it.
        DataFixture(
            AttributeFixture::class,
            ['attribute_code' => 'size', 'frontend_label' => 'Size', 'options' => ['S', 'M', 'L'], 'reuse' => true],
            'size'
        ),
        DataFixture(AttributeFixture::class, ['frontend_label' => 'Fit', 'options' => ['Regular']], 'fit'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-s', 'name' => 'Tee S', 'price' => 10], 's'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-m', 'name' => 'Tee M', 'price' => 20], 'm'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-l', 'name' => 'Tee L', 'price' => 30], 'l'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku'      => self::SKU,
                'name'     => self::NAME,
                'url_key'  => self::SKU,
                '_options' => ['$size$', '$fit$'],
                '_links'   => ['$s$', '$m$', '$l$'],
            ],
            'configurable'
        ),
    ]
    public function testWithinTheCapTheProductIsAGroupOfItsVariants(): void
    {
        $node     = $this->productNode($this->productPage('configurable'));
        $variants = $node['hasVariant'] ?? [];
        unset($node['hasVariant']);

        $this->assertSame(
            [
                '@context'       => 'https://schema.org',
                '@type'          => 'ProductGroup',
                '@id'            => self::URL . '#product',
                'name'           => self::NAME,
                'url'            => self::URL,
                'sku'            => self::SKU,
                'image'          => self::PLACEHOLDER,
                'productGroupID' => self::SKU,
                'variesBy'       => ['https://schema.org/size'],
            ],
            $node,
            'The group carries no offer; size varies, the attribute with an ordinary code does not.'
        );

        $fitCode = (string) DataFixtureStorageManager::getStorage()->get('fit')->getAttributeCode();
        $bySku   = $this->bySku($variants);
        $this->assertSame([self::SKU . '-l', self::SKU . '-m', self::SKU . '-s'], array_keys($bySku));

        foreach (['S' => '10.00', 'M' => '20.00', 'L' => '30.00'] as $suffix => $price) {
            $sku   = self::SKU . '-' . strtolower($suffix);
            $child = $this->productRepository()->get($sku);
            // The size a child was given depends on the attribute's options, which a reused
            // attribute brings with it: read it back rather than assume it.
            $size = (string) $child->getAttributeText('size');
            $this->assertNotSame('', $size, $sku . ' has a size');

            $variant = $bySku[$sku];
            $variant['offers'] = $this->withoutPriceValidUntil($variant['offers'] ?? []);

            $this->assertSame(
                [
                    '@type'              => 'Product',
                    'name'               => 'Tee ' . $suffix,
                    'sku'                => $sku,
                    'image'              => self::PLACEHOLDER,
                    'size'               => $size,
                    'additionalProperty' => [['@type' => 'PropertyValue', 'name' => 'Fit', 'value' => 'Regular']],
                    'offers'             => [
                        '@type'         => 'Offer',
                        'url'           => self::URL . '?size=' . $child->getData('size')
                            . '&' . $fitCode . '=' . $child->getData($fitCode),
                        'price'         => $price,
                        'priceCurrency' => 'USD',
                        'availability'  => 'https://schema.org/InStock',
                        'itemCondition' => 'https://schema.org/NewCondition',
                    ],
                ],
                $variant,
                'Variant ' . $sku
            );
        }
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/structured_data/has_variant_max 2
     * @return void
     */
    #[
        DataFixture(AttributeFixture::class, ['options' => ['S', 'M', 'L']], 'size'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-s', 'price' => 10], 's'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-m', 'price' => 20], 'm'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-l', 'price' => 30], 'l'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku'      => self::SKU,
                'url_key'  => self::SKU,
                '_options' => ['$size$'],
                '_links'   => ['$s$', '$m$', '$l$'],
            ],
            'configurable'
        ),
    ]
    public function testAboveTheCapTheProductHasAnAggregateOfferOverEveryChild(): void
    {
        $this->assertAggregateOffer($this->productNode($this->productPage('configurable')), '10.00', '30.00');
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/structured_data/has_variant_max 0
     * @return void
     */
    #[
        DataFixture(AttributeFixture::class, ['options' => ['S', 'M', 'L']], 'size'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-s', 'price' => 10], 's'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-m', 'price' => 20], 'm'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-l', 'price' => 30], 'l'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku'      => self::SKU,
                'url_key'  => self::SKU,
                '_options' => ['$size$'],
                '_links'   => ['$s$', '$m$', '$l$'],
            ],
            'configurable'
        ),
    ]
    public function testZeroTurnsVariantsOff(): void
    {
        $this->assertAggregateOffer($this->productNode($this->productPage('configurable')), '10.00', '30.00');
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/structured_data/has_variant_max 0
     * @return void
     */
    #[
        DataFixture(AttributeFixture::class, ['options' => ['S', 'M', 'L']], 'size'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-s', 'price' => 15], 's'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-m', 'price' => 15], 'm'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-l', 'price' => 15], 'l'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku'      => self::SKU,
                'url_key'  => self::SKU,
                '_options' => ['$size$'],
                '_links'   => ['$s$', '$m$', '$l$'],
            ],
            'configurable'
        ),
    ]
    public function testChildrenAtOnePriceKeepASingleOffer(): void
    {
        $node = $this->productNode($this->productPage('configurable'));

        $this->assertSame('Product', $node['@type'] ?? null);
        $this->assertSame(
            [
                '@type'         => 'Offer',
                'url'           => self::URL,
                'price'         => '15.00',
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'itemCondition' => 'https://schema.org/NewCondition',
            ],
            $this->withoutPriceValidUntil($node['offers'] ?? [])
        );
    }

    /**
     * The barcode attribute is not used in product listing, so core's children arrive without it.
     *
     * @return void
     */
    #[
        DataFixture(AttributeFixture::class, ['attribute_code' => 'barcode', 'frontend_input' => 'text'], 'barcode'),
        DataFixture(AttributeFixture::class, ['options' => ['S', 'M', 'L']], 'size'),
        DataFixture(CategoryFixture::class, as: 'category'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-s', 'barcode' => '5901234123457'], 's'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-m', 'barcode' => '4006381333931'], 'm'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-l', 'barcode' => '5901234123450'], 'l'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku'          => self::SKU,
                'url_key'      => self::SKU,
                'category_ids' => ['$category.id$'],
                '_options'     => ['$size$'],
                '_links'       => ['$s$', '$m$', '$l$'],
            ],
            'configurable'
        ),
    ]
    public function testVariantsCarryTheirGtinWhenTheTemplateEmitsOne(): void
    {
        $categoryId = (int) DataFixtureStorageManager::getStorage()->get('category')->getId();
        Bootstrap::getObjectManager()->get(ConfigRepository::class)
            ->save($categoryId, ['enabled_fields' => ['gtin13']]);

        $bySku = $this->bySku($this->productNode($this->productPage('configurable'))['hasVariant'] ?? []);

        $this->assertCount(3, $bySku);
        $this->assertSame('5901234123457', $bySku[self::SKU . '-s']['gtin13'] ?? null);
        $this->assertSame('4006381333931', $bySku[self::SKU . '-m']['gtin13'] ?? null);
        $this->assertArrayNotHasKey(
            'gtin13',
            $bySku[self::SKU . '-l'],
            'A barcode failing its check digit is left out.'
        );
    }

    /**
     * @return void
     */
    #[
        DataFixture(AttributeFixture::class, ['attribute_code' => 'barcode', 'frontend_input' => 'text'], 'barcode'),
        DataFixture(AttributeFixture::class, ['options' => ['S', 'M', 'L']], 'size'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-s', 'barcode' => '5901234123457'], 's'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-m', 'barcode' => '4006381333931'], 'm'),
        DataFixture(ProductFixture::class, ['sku' => self::SKU . '-l', 'barcode' => '5901234123457'], 'l'),
        DataFixture(
            ConfigurableProductFixture::class,
            [
                'sku'      => self::SKU,
                'url_key'  => self::SKU,
                '_options' => ['$size$'],
                '_links'   => ['$s$', '$m$', '$l$'],
            ],
            'configurable'
        ),
    ]
    public function testVariantsCarryNoGtinWhenTheTemplateFieldIsOff(): void
    {
        $bySku = $this->bySku($this->productNode($this->productPage('configurable'))['hasVariant'] ?? []);

        $this->assertCount(3, $bySku);
        $gtinProperties = array_flip(['gtin8', 'gtin12', 'gtin13', 'gtin14']);
        foreach ($bySku as $sku => $variant) {
            $this->assertSame([], array_intersect_key($variant, $gtinProperties), $sku);
        }
    }

    /**
     * Assert the node is one Product whose offer is an AggregateOffer over the given range.
     *
     * @param array<string,mixed> $node
     * @param string $low
     * @param string $high
     * @return void
     */
    private function assertAggregateOffer(array $node, string $low, string $high): void
    {
        $this->assertSame('Product', $node['@type'] ?? null);
        $this->assertArrayNotHasKey('hasVariant', $node);
        $this->assertSame(
            [
                '@type'         => 'AggregateOffer',
                'url'           => self::URL,
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'lowPrice'      => $low,
                'highPrice'     => $high,
                'itemCondition' => 'https://schema.org/NewCondition',
            ],
            $this->withoutPriceValidUntil($node['offers'] ?? [])
        );
    }

    /**
     * Variant nodes keyed by SKU, sorted, since the order core returns children in is not part of the contract.
     *
     * @param array<int,array<string,mixed>> $variants
     * @return array<string,array<string,mixed>>
     */
    private function bySku(array $variants): array
    {
        $bySku = array_column($variants, null, 'sku');
        ksort($bySku);

        return $bySku;
    }

    /**
     * @return ProductRepositoryInterface
     */
    private function productRepository(): ProductRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
    }
}
