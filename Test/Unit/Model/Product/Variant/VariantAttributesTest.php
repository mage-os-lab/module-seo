<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Variant;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\DataObject;
use MageOS\Seo\Model\Product\Variant\VariantAttribute;
use MageOS\Seo\Model\Product\Variant\VariantAttributes;
use PHPUnit\Framework\TestCase;

/**
 * A configurable's own attributes, and which of them are Google's `variesBy` properties: matched
 * by code, case and underscores aside, with no map to maintain.
 */
class VariantAttributesTest extends TestCase
{
    public function testGooglesCodesAreMatchedAndEveryOtherAttributeIsNot(): void
    {
        $product = $this->configurable([
            $this->eavAttribute('color', 'Colour', ['49' => 'Red']),
            $this->eavAttribute('Suggested_Gender', 'Gender', ['7' => 'female']),
            $this->eavAttribute('gender', 'Gender (catalog)', ['8' => 'Men']),
            $this->eavAttribute('fit', 'Fit', ['12' => 'Slim']),
        ]);

        $attributes = (new VariantAttributes())->forProduct($product);

        $this->assertSame(
            [
                ['color', 'Colour', 'color'],
                ['Suggested_Gender', 'Gender', 'suggestedGender'],
                ['gender', 'Gender (catalog)', null],
                ['fit', 'Fit', null],
            ],
            array_map(
                static fn (VariantAttribute $attribute): array => [
                    $attribute->code,
                    $attribute->label,
                    $attribute->property,
                ],
                $attributes
            )
        );
    }

    public function testAVariantsOptionIsReadAsItsStoreLabel(): void
    {
        $attributes = (new VariantAttributes())->forProduct(
            $this->configurable([$this->eavAttribute('color', 'Colour', ['49' => 'Red', '50' => 'Blue'])])
        );
        $variant = $this->createStub(Product::class);
        $variant->method('getData')->willReturnCallback(static fn (string $key = '') => $key === 'color' ? '50' : null);

        $this->assertSame('50', $attributes[0]->optionId($variant));
        $this->assertSame('Blue', $attributes[0]->optionLabel($variant));
    }

    public function testAnyOtherProductTypeHasNone(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn('simple');

        $this->assertSame([], (new VariantAttributes())->forProduct($product));
    }

    public function testTheAttributesAreReadOncePerProductAndStore(): void
    {
        $type = $this->createMock(Configurable::class);
        $type->expects($this->once())->method('getConfigurableAttributes')->willReturn([]);
        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getId')->willReturn(5);
        $product->method('getStoreId')->willReturn(1);

        $variantAttributes = new VariantAttributes();
        $variantAttributes->forProduct($product);
        $variantAttributes->forProduct($product);
    }

    /**
     * @param Attribute[] $eavAttributes
     * @return Product
     */
    private function configurable(array $eavAttributes): Product
    {
        // Core's configurable attribute model exposes getProductAttribute() through __call.
        $configurableAttributes = array_map(
            static fn (Attribute $attribute): DataObject => new DataObject(['product_attribute' => $attribute]),
            $eavAttributes
        );

        $type = $this->createStub(Configurable::class);
        $type->method('getConfigurableAttributes')->willReturn($configurableAttributes);

        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getId')->willReturn(5);
        $product->method('getStoreId')->willReturn(1);

        return $product;
    }

    /**
     * @param string $code
     * @param string $label
     * @param array<int|string, string> $options Option ID => label
     * @return Attribute
     */
    private function eavAttribute(string $code, string $label, array $options): Attribute
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getAllOptions')->willReturn(
            array_map(
                static fn ($id, $text): array => ['value' => $id, 'label' => $text],
                array_keys($options),
                $options
            )
        );

        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getStoreLabel')->willReturn($label);
        $attribute->method('getSource')->willReturn($source);

        return $attribute;
    }
}
