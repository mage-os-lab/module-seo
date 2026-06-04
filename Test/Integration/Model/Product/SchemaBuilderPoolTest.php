<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Product;

use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Model\Product\SchemaBuilderPool;

/**
 * @magentoAppArea frontend
 */
class SchemaBuilderPoolTest extends TestCase
{
    /**
     * @var SchemaBuilderPool
     */
    private SchemaBuilderPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool = Bootstrap::getObjectManager()->get(SchemaBuilderPool::class);
    }

    public function testAllSixteenBuiltInTemplatesAreRegistered(): void
    {
        $templates = $this->pool->getAvailableTemplates();
        $expected  = [
            'GenericProduct',
            'Food',
            'Apparel',
            'Jewelry',
            'HomeDecor',
            'Book',
            'Software',
            'Toy',
            'HealthProduct',
            'Cosmetics',
            'Pet',
            'ArtAndCraft',
            'ElectronicsSimple',
            'Tool',
            'Stationery',
            'LocalExperience',
        ];
        foreach ($expected as $code) {
            $this->assertArrayHasKey($code, $templates, "Template '{$code}' is missing from the pool");
        }
    }

    public function testEveryRegisteredTemplateHasANonEmptyLabel(): void
    {
        foreach ($this->pool->getAvailableTemplates() as $code => $label) {
            $this->assertNotSame('', $label, "Template '{$code}' has an empty label");
            $this->assertIsString($label);
        }
    }

    public function testGetAvailableFieldsReturnsAnArrayForEveryTemplate(): void
    {
        foreach (array_keys($this->pool->getAvailableTemplates()) as $code) {
            $fields = $this->pool->getAvailableFields($code);
            $this->assertIsArray($fields, "getAvailableFields('{$code}') must return an array");
        }
    }

    public function testBuildReturnsEmptyArrayForUnknownTemplateWithNoGenericFallback(): void
    {
        $emptyPool = new SchemaBuilderPool([]);
        $product   = $this->createMock(\Magento\Catalog\Api\Data\ProductInterface::class);
        $result    = $emptyPool->build('NonExistent', $product, [], [], []);
        $this->assertSame([], $result);
    }

    public function testGetAvailableFieldsReturnsEmptyArrayForUnknownTemplate(): void
    {
        $this->assertSame([], $this->pool->getAvailableFields('NonExistentTemplate'));
    }
}
