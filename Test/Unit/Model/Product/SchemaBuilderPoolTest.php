<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Api\ProductSchemaBuilderInterface;
use MageOS\Seo\Model\Product\SchemaBuilderPool;

class SchemaBuilderPoolTest extends TestCase
{
    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $result
     */
    private function makeBuilder(
        string $code,
        string $label,
        array $fields = [],
        array $result = []
    ): ProductSchemaBuilderInterface&MockObject {
        $builder = $this->createMock(ProductSchemaBuilderInterface::class);
        $builder->method('getTemplateCode')->willReturn($code);
        $builder->method('getLabel')->willReturn($label);
        $builder->method('getAvailableFields')->willReturn($fields);
        $builder->method('build')->willReturn($result);
        return $builder;
    }

    public function testBuildDispatchesToRegisteredBuilder(): void
    {
        $expected = ['@type' => 'Apparel', 'name' => 'T-Shirt'];
        $product  = $this->createMock(ProductInterface::class);
        $builder  = $this->makeBuilder('Apparel', 'Clothing', [], $expected);
        $pool     = new SchemaBuilderPool(['Apparel' => $builder]);

        $result = $pool->build('Apparel', $product, [], [], []);
        $this->assertSame($expected, $result);
    }

    public function testBuildFallsBackToGenericProductWhenTemplateNotFound(): void
    {
        $expected = ['@type' => 'Product', 'name' => 'Widget'];
        $product  = $this->createMock(ProductInterface::class);
        $generic  = $this->makeBuilder('GenericProduct', 'Generic Product', [], $expected);
        $pool     = new SchemaBuilderPool(['GenericProduct' => $generic]);

        $result = $pool->build('UnknownTemplate', $product, [], [], []);
        $this->assertSame($expected, $result);
    }

    public function testBuildReturnsEmptyArrayWhenNoBuilderAndNoGenericFallback(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $pool    = new SchemaBuilderPool([]);

        $result = $pool->build('Apparel', $product, [], [], []);
        $this->assertSame([], $result);
    }

    public function testBuildPassesArgumentsToBuilder(): void
    {
        $product       = $this->createMock(ProductInterface::class);
        $enabledFields = ['brand', 'color'];
        $overrides     = ['brand' => 'Acme'];
        $variantData   = ['color' => 'Red'];
        $builder       = $this->createMock(ProductSchemaBuilderInterface::class);
        $builder->method('getTemplateCode')->willReturn('Apparel');
        $builder->expects($this->once())
            ->method('build')
            ->with($product, $enabledFields, $overrides, $variantData)
            ->willReturn(['@type' => 'Apparel']);

        $pool   = new SchemaBuilderPool(['Apparel' => $builder]);
        $result = $pool->build('Apparel', $product, $enabledFields, $overrides, $variantData);
        $this->assertSame(['@type' => 'Apparel'], $result);
    }

    public function testGetAvailableTemplatesReturnsAllRegistered(): void
    {
        $b1  = $this->makeBuilder('GenericProduct', 'Generic Product');
        $b2  = $this->makeBuilder('Apparel', 'Clothing & Apparel');
        $pool = new SchemaBuilderPool(['GenericProduct' => $b1, 'Apparel' => $b2]);

        $templates = $pool->getAvailableTemplates();
        $this->assertArrayHasKey('GenericProduct', $templates);
        $this->assertArrayHasKey('Apparel', $templates);
        $this->assertSame('Generic Product', $templates['GenericProduct']);
        $this->assertSame('Clothing & Apparel', $templates['Apparel']);
    }

    public function testGetAvailableTemplatesSkipsNonBuilderObjects(): void
    {
        $builder = $this->makeBuilder('GenericProduct', 'Generic Product');
        $pool    = new SchemaBuilderPool(['GenericProduct' => $builder, 'bad' => new \stdClass()]);

        $templates = $pool->getAvailableTemplates();
        $this->assertCount(1, $templates);
        $this->assertArrayHasKey('GenericProduct', $templates);
    }

    public function testGetAvailableTemplatesReturnsEmptyArrayForEmptyPool(): void
    {
        $pool = new SchemaBuilderPool([]);
        $this->assertSame([], $pool->getAvailableTemplates());
    }

    public function testGetAvailableFieldsForRegisteredTemplate(): void
    {
        $fields  = ['brand' => 'Brand', 'color' => 'Colour'];
        $builder = $this->makeBuilder('Apparel', 'Clothing', $fields);
        $pool    = new SchemaBuilderPool(['Apparel' => $builder]);

        $this->assertSame($fields, $pool->getAvailableFields('Apparel'));
    }

    public function testGetAvailableFieldsForUnknownTemplateReturnsEmpty(): void
    {
        $pool = new SchemaBuilderPool([]);
        $this->assertSame([], $pool->getAvailableFields('UnknownTemplate'));
    }

    public function testGetAvailableFieldsForNonBuilderObjectReturnsEmpty(): void
    {
        $pool = new SchemaBuilderPool(['bad' => new \stdClass()]);
        $this->assertSame([], $pool->getAvailableFields('bad'));
    }

    public function testBuildPrefersExactTemplateOverGenericFallback(): void
    {
        $exactResult   = ['@type' => 'Apparel'];
        $genericResult = ['@type' => 'Product'];
        $product       = $this->createMock(ProductInterface::class);
        $exact         = $this->makeBuilder('Apparel', 'Clothing', [], $exactResult);
        $generic       = $this->makeBuilder('GenericProduct', 'Generic', [], $genericResult);
        $pool          = new SchemaBuilderPool(['Apparel' => $exact, 'GenericProduct' => $generic]);

        $result = $pool->build('Apparel', $product, [], [], []);
        $this->assertSame($exactResult, $result);
    }
}
