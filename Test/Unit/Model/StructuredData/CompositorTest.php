<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Product\SchemaRegistry;
use MageOS\Seo\Model\StructuredData\Compositor;

class CompositorTest extends TestCase
{
    /**
     * @var Layout&MockObject
     */
    private Layout&MockObject $layout;

    /**
     * @var ProcessorInterface&MockObject
     */
    private ProcessorInterface&MockObject $layoutUpdate;

    /**
     * @var SchemaRegistry
     */
    private SchemaRegistry $schemaRegistry;

    protected function setUp(): void
    {
        $this->layout         = $this->createMock(Layout::class);
        $this->layoutUpdate   = $this->createMock(ProcessorInterface::class);
        $this->schemaRegistry = new SchemaRegistry();

        $this->layout->method('getUpdate')->willReturn($this->layoutUpdate);
    }

    /**
     * @param string[] $handles
     * @param array<int, array<string, mixed>> $schemas
     */
    private function makeProvider(array $handles, array $schemas): StructuredDataProviderInterface&MockObject
    {
        $provider = $this->createMock(StructuredDataProviderInterface::class);
        $provider->method('getHandles')->willReturn($handles);
        $provider->method('getSchemas')->willReturn($schemas);
        return $provider;
    }

    public function testReturnsEmptyStringWithNoProviders(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, []);
        $this->assertSame('', $compositor->render());
    }

    public function testReturnsEmptyStringWhenNonMatchingProvider(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(['catalog_product_view'], [['@type' => 'Product']]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $this->assertSame('', $compositor->render());
    }

    public function testRendersJsonFromMatchingProvider(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $schema   = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Widget'];
        $provider = $this->makeProvider(['catalog_product_view'], [$schema]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $json = $compositor->render();
        $this->assertNotSame('', $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Widget', $decoded[0]['name']);
    }

    public function testWildcardHandleMatchesAnyPage(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $schema   = ['@context' => 'https://schema.org', '@type' => 'WebSite'];
        $provider = $this->makeProvider(['*'], [$schema]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $json = $compositor->render();
        $decoded = json_decode($json, true);
        $this->assertSame('WebSite', $decoded[0]['@type']);
    }

    public function testEmptySchemaArraysFromProviderAreFiltered(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(['*'], [[], ['@type' => 'Product']]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $json = $compositor->render();
        $decoded = json_decode($json, true);
        $this->assertCount(1, $decoded);
    }

    public function testProductSchemaFromRegistryIsAppended(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $this->schemaRegistry->set(['@type' => 'Product', 'name' => 'From Registry']);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, []);
        $json = $compositor->render();
        $decoded = json_decode($json, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('From Registry', $decoded[0]['name']);
    }

    public function testProductSchemaFromRegistryAppendedAfterProviderSchemas(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $this->schemaRegistry->set(['@type' => 'Product', 'name' => 'Product']);
        $orgSchema = ['@context' => 'https://schema.org', '@type' => 'Organization'];
        $provider  = $this->makeProvider(['*'], [$orgSchema]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $json = $compositor->render();
        $decoded = json_decode($json, true);
        $this->assertCount(2, $decoded);
        $this->assertSame('Organization', $decoded[0]['@type']);
        $this->assertSame('Product', $decoded[1]['@type']);
    }

    public function testNullRegistrySchemaIsNotAppended(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(['*'], [['@type' => 'Organization']]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $json = $compositor->render();
        $decoded = json_decode($json, true);
        $this->assertCount(1, $decoded);
    }

    public function testXssProtectionEscapesScriptClosingTag(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $schema   = ['@type' => 'Product', 'name' => 'Widget</script><script>alert(1)'];
        $provider = $this->makeProvider(['*'], [$schema]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $json = $compositor->render();
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringContainsString('<\/', $json);
    }

    public function testXssProtectionEscapesHtmlComment(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $schema   = ['@type' => 'Product', 'name' => '<!--comment-->'];
        $provider = $this->makeProvider(['*'], [$schema]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$provider]);
        $json = $compositor->render();
        $this->assertStringNotContainsString('<!--', $json);
        $this->assertStringContainsString('<\!--', $json);
    }

    public function testOutputIsValidJsonArray(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $s1 = ['@context' => 'https://schema.org', '@type' => 'WebSite'];
        $s2 = ['@context' => 'https://schema.org', '@type' => 'Organization'];
        $p1 = $this->makeProvider(['*'], [$s1]);
        $p2 = $this->makeProvider(['*'], [$s2]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [$p1, $p2]);
        $json = $compositor->render();
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
    }

    public function testNonProviderObjectsInArrayAreSkipped(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, [new \stdClass()]);
        $this->assertSame('', $compositor->render());
    }

    public function testEmptyRegistrySchemaArrayIsNotAppended(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $this->schemaRegistry->set([]);
        $compositor = new Compositor($this->layout, $this->schemaRegistry, []);
        $this->assertSame('', $compositor->render());
    }
}
