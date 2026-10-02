<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use MageOS\Seo\Api\ProductSchemaBuilderInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\SchemaTemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Which product schema template is in effect: the storefront builds with it, and the category
 * form lists its fields.
 */
class SchemaTemplateResolverTest extends TestCase
{
    public function testTheCategorysTemplateWins(): void
    {
        $this->assertSame('Apparel', $this->resolver('Book')->resolve('Apparel', 1));
    }

    public function testWithoutACategoryTemplateTheStoreDefaultApplies(): void
    {
        $this->assertSame('Book', $this->resolver('Book')->resolve('', 1));
    }

    public function testWithNeitherItIsTheGenericTemplate(): void
    {
        $this->assertSame('GenericProduct', $this->resolver('')->resolve('', 1));
    }

    /**
     * A template whose module has gone, or a code saved before the setting was a select: the
     * storefront builds GenericProduct for it, so that is the template in effect.
     */
    public function testACodeNoBuilderIsRegisteredForIsTheGenericTemplate(): void
    {
        $this->assertSame('GenericProduct', $this->resolver('Book')->resolve('Retired', 1));
        $this->assertSame('GenericProduct', $this->resolver('Typo')->resolve('', 1));
    }

    public function testTheStoreDefaultIsReadForTheStoreAsked(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('getDefaultProductTemplate')->with(3)->willReturn('Book');

        $this->assertSame('Book', (new SchemaTemplateResolver($config, $this->pool()))->resolve('', 3));
    }

    /**
     * A resolver whose store default is the given code.
     *
     * @param string $storeDefault
     * @return SchemaTemplateResolver
     */
    private function resolver(string $storeDefault): SchemaTemplateResolver
    {
        $config = $this->createStub(Config::class);
        $config->method('getDefaultProductTemplate')->willReturn($storeDefault);

        return new SchemaTemplateResolver($config, $this->pool());
    }

    /**
     * A pool with GenericProduct, Apparel and Book registered.
     *
     * @return SchemaBuilderPool
     */
    private function pool(): SchemaBuilderPool
    {
        return new SchemaBuilderPool([
            'GenericProduct' => $this->createStub(ProductSchemaBuilderInterface::class),
            'Apparel'        => $this->createStub(ProductSchemaBuilderInterface::class),
            'Book'           => $this->createStub(ProductSchemaBuilderInterface::class),
        ]);
    }
}
