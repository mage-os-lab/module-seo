<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use MageOS\Seo\Model\Config\Source\SchemaTemplate;
use MageOS\Seo\Model\Config\Source\SchemaTemplate\CategoryOverride;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SchemaTemplateTest extends TestCase
{
    /**
     * @var SchemaBuilderPool&Stub
     */
    private SchemaBuilderPool&Stub $pool;

    /**
     * @var SchemaTemplate
     */
    private SchemaTemplate $source;

    protected function setUp(): void
    {
        $this->pool   = $this->createStub(SchemaBuilderPool::class);
        $this->source = new SchemaTemplate($this->pool);
    }

    /**
     * The store's default template is always a template: there is nothing above it to inherit.
     */
    public function testListsTheRegisteredTemplatesWithNoEmptyOption(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([
            'GenericProduct' => 'Generic Product',
            'Apparel'        => 'Clothing & Apparel',
        ]);

        $this->assertSame(
            [
                ['value' => 'GenericProduct', 'label' => 'Generic Product'],
                ['value' => 'Apparel', 'label' => 'Clothing & Apparel'],
            ],
            $this->source->toOptionArray()
        );
    }

    /**
     * A select shows a stored value it does not list as its first option, and a save stores that
     * option. GenericProduct is what the storefront builds for a code no builder has, so with it
     * first such a value is shown, and kept, as what already happens.
     */
    public function testTheGenericTemplateComesFirstAndTheRestKeepTheirOrder(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([
            'Apparel'        => 'Clothing & Apparel',
            'GenericProduct' => 'Generic Product',
            'Book'           => 'Book',
        ]);

        $this->assertSame(
            ['GenericProduct', 'Apparel', 'Book'],
            array_column($this->source->toOptionArray(), 'value')
        );
    }

    public function testAnEmptyPoolListsNothing(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([]);

        $this->assertSame([], $this->source->toOptionArray());
    }

    public function testEachOptionHasValueAndLabelKeys(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([
            'Book' => 'Book',
        ]);
        foreach ($this->source->toOptionArray() as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    public function testTemplateCodesArePreservedAsOptionValues(): void
    {
        $templates = [
            'FoodProduct'   => 'Food & Grocery',
            'Electronics'   => 'Electronics',
            'Jewelry'       => 'Jewellery',
        ];
        $this->pool->method('getAvailableTemplates')->willReturn($templates);
        $options = $this->source->toOptionArray();
        $values  = array_column($options, 'value');
        foreach (array_keys($templates) as $code) {
            $this->assertContains($code, $values);
        }
    }

    /**
     * The category form's list says what an empty value falls back to, once, and otherwise offers
     * exactly the templates the store-level list does.
     */
    public function testTheCategoryFormListsOneInheritOptionFirstThenTheSameTemplates(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([
            'Apparel'        => 'Clothing & Apparel',
            'GenericProduct' => 'Generic Product',
        ]);

        $options = (new CategoryOverride($this->pool))->toOptionArray();

        $this->assertSame(
            ['value' => '', 'label' => "Inherit (parent category, then the store's default template)"],
            $options[0]
        );
        $this->assertSame($this->source->toOptionArray(), \array_slice($options, 1));
    }
}
