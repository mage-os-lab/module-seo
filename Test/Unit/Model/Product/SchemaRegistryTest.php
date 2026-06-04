<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use PHPUnit\Framework\TestCase;
use MageOS\Seo\Model\Product\SchemaRegistry;

class SchemaRegistryTest extends TestCase
{
    /**
     * @var SchemaRegistry
     */
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SchemaRegistry();
    }

    public function testGetReturnsNullInitially(): void
    {
        $this->assertNull($this->registry->get());
    }

    public function testHasReturnsFalseInitially(): void
    {
        $this->assertFalse($this->registry->has());
    }

    public function testSetAndGetRoundTrip(): void
    {
        $schema = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Widget'];
        $this->registry->set($schema);
        $this->assertSame($schema, $this->registry->get());
    }

    public function testHasReturnsTrueAfterSet(): void
    {
        $this->registry->set(['@type' => 'Product']);
        $this->assertTrue($this->registry->has());
    }

    public function testSetOverwritesPreviousSchema(): void
    {
        $this->registry->set(['name' => 'First']);
        $this->registry->set(['name' => 'Second']);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertSame('Second', $schema['name']);
    }

    public function testSetWithEmptyArrayIsStillConsideredSet(): void
    {
        $this->registry->set([]);
        $this->assertTrue($this->registry->has());
        $this->assertSame([], $this->registry->get());
    }

    public function testMergeWhenNullInitializesFromGivenFields(): void
    {
        $this->registry->merge(['@type' => 'Product', 'name' => 'Widget']);
        $this->assertSame(['@type' => 'Product', 'name' => 'Widget'], $this->registry->get());
    }

    public function testMergeOverridesExistingTopLevelField(): void
    {
        $this->registry->set(['@type' => 'Product', 'name' => 'Original', 'sku' => 'SKU-1']);
        $this->registry->merge(['name' => 'Updated']);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertSame('Updated', $schema['name']);
        $this->assertSame('SKU-1', $schema['sku']);
    }

    public function testMergeAddsNewTopLevelField(): void
    {
        $this->registry->set(['@type' => 'Product', 'name' => 'Widget']);
        $this->registry->merge(['description' => 'A great product']);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertSame('A great product', $schema['description']);
    }

    public function testMergeIsRecursiveForNestedArrays(): void
    {
        $this->registry->set([
            'offers' => ['@type' => 'Offer', 'price' => '10.00'],
        ]);
        $this->registry->merge([
            'offers' => ['priceCurrency' => 'GBP'],
        ]);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertSame('10.00', $schema['offers']['price']);
        $this->assertSame('GBP', $schema['offers']['priceCurrency']);
    }

    public function testMergeNestedDoesNothingWhenSchemaIsNull(): void
    {
        $this->registry->mergeNested('offers', ['price' => '9.99']);
        $this->assertNull($this->registry->get());
    }

    public function testMergeNestedCreatesNewKeyWhenKeyAbsent(): void
    {
        $this->registry->set(['@type' => 'Product', 'name' => 'Widget']);
        $this->registry->mergeNested('offers', ['price' => '9.99', 'priceCurrency' => 'GBP']);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertArrayHasKey('offers', $schema);
        $this->assertSame('9.99', $schema['offers']['price']);
        $this->assertSame('GBP', $schema['offers']['priceCurrency']);
    }

    public function testMergeNestedAppendsToExistingKey(): void
    {
        $this->registry->set([
            '@type' => 'Product',
            'offers' => ['@type' => 'Offer', 'price' => '5.00'],
        ]);
        $this->registry->mergeNested('offers', ['color' => 'Red']);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertSame('5.00', $schema['offers']['price']);
        $this->assertSame('Red', $schema['offers']['color']);
    }

    public function testMergeNestedDoesNotCrossContaminateKeys(): void
    {
        $this->registry->set([
            '@type' => 'Product',
            'brand' => ['@type' => 'Brand', 'name' => 'Acme'],
        ]);
        $this->registry->mergeNested('offers', ['price' => '1.00']);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertSame('Acme', $schema['brand']['name']);
        $this->assertSame('1.00', $schema['offers']['price']);
    }

    public function testMultipleMergesAccumulate(): void
    {
        $this->registry->set(['@type' => 'Product']);
        $this->registry->merge(['name' => 'Widget']);
        $this->registry->merge(['sku' => 'W-001']);
        $this->registry->merge(['description' => 'Nice widget']);
        $schema = $this->registry->get();
        $this->assertNotNull($schema);
        $this->assertSame('Widget', $schema['name']);
        $this->assertSame('W-001', $schema['sku']);
        $this->assertSame('Nice widget', $schema['description']);
    }
}
