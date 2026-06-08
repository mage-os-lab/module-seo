<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use MageOS\Seo\Model\Config\Source\SchemaTemplate;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SchemaTemplateTest extends TestCase
{
    /**
     * @var SchemaBuilderPool&MockObject
     */
    private SchemaBuilderPool&MockObject $pool;

    /**
     * @var SchemaTemplate
     */
    private SchemaTemplate $source;

    protected function setUp(): void
    {
        $this->pool   = $this->createMock(SchemaBuilderPool::class);
        $this->source = new SchemaTemplate($this->pool);
    }

    public function testFirstOptionIsInheritDefault(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([]);
        $options = $this->source->toOptionArray();
        $this->assertSame('', $options[0]['value']);
        $this->assertStringContainsString('Inherit', $options[0]['label']);
    }

    public function testBuilderTemplatesAreAppendedAfterDefault(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([
            'GenericProduct' => 'Generic Product',
            'Apparel'        => 'Clothing & Apparel',
        ]);
        $options = $this->source->toOptionArray();
        $this->assertCount(3, $options);
        $this->assertSame('GenericProduct', $options[1]['value']);
        $this->assertSame('Generic Product', $options[1]['label']);
        $this->assertSame('Apparel', $options[2]['value']);
        $this->assertSame('Clothing & Apparel', $options[2]['label']);
    }

    public function testOnlyDefaultOptionWhenPoolIsEmpty(): void
    {
        $this->pool->method('getAvailableTemplates')->willReturn([]);
        $options = $this->source->toOptionArray();
        $this->assertCount(1, $options);
        $this->assertSame('', $options[0]['value']);
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
}
