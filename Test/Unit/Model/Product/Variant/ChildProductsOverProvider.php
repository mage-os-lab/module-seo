<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Variant;

use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsFilterInterface;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use MageOS\Seo\Model\Product\Variant\ChildLoader;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Model\ResourceModel\VariantLinks;
use Psr\Log\LoggerInterface;

/**
 * A real ChildProducts answering from core's provider, for a test whose subject reads a product's
 * children without prefetching a page of them.
 *
 * Expects to be used in a PHPUnit TestCase.
 */
trait ChildProductsOverProvider
{
    /**
     * ChildProducts over the given provider, its page-reading collaborators stubbed.
     *
     * @param ConfigurableOptionsProviderInterface $provider
     * @return ChildProducts
     */
    private function childProductsOver(ConfigurableOptionsProviderInterface $provider): ChildProducts
    {
        // The storefront filter passes the provider's children through: these tests' are all sold.
        $filter = $this->createStub(ConfigurableOptionsFilterInterface::class);
        $filter->method('filter')->willReturnArgument(1);

        return new ChildProducts(
            $provider,
            $filter,
            $this->createStub(VariantLinks::class),
            $this->createStub(ChildLoader::class),
            $this->createStub(LoggerInterface::class)
        );
    }
}
