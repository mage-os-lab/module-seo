<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config as EavConfig;

/**
 * Reads the GTIN of each variant of a configurable product, in one load.
 *
 * Core's children carry only the attributes used in product listing, and a barcode attribute
 * rarely is, so the values are loaded here: one collection over the variants, selecting the GTIN
 * attributes the install has. A variant's GTIN is its first non-empty value among
 * ATTRIBUTE_CODES, in that order; it is validated where it is written, and left out if it fails.
 */
class GtinReader
{
    /**
     * The attribute codes a variant's GTIN is read from, first non-empty wins.
     */
    public const ATTRIBUTE_CODES = ['gtin13', 'gtin', 'barcode', 'ean'];

    /**
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * The raw GTIN of each variant that has one.
     *
     * @param ProductInterface $product The configurable product
     * @param ProductInterface[] $variants Its children to read
     * @return array<int, string> Variant ID => raw value
     */
    public function read(ProductInterface $product, array $variants): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $type  = $product->getTypeInstance();
        $codes = array_values(array_filter(self::ATTRIBUTE_CODES, fn (string $code): bool => $this->exists($code)));
        $ids   = array_map(static fn (ProductInterface $variant): int => (int) $variant->getId(), $variants);
        if ($codes === [] || $ids === [] || !$type instanceof Configurable) {
            return [];
        }

        // Core's child collection: filtered to this product's children, and never read from the
        // flat catalog, which holds only the attributes used in listing.
        $collection = $type->getUsedProductCollection($product)
            ->addAttributeToSelect($codes)
            ->addIdFilter($ids)
            ->setStoreId((int) $product->getStoreId());

        $gtins = [];
        foreach ($collection as $variant) {
            foreach ($codes as $code) {
                $value = trim((string) $variant->getData($code));
                if ($value !== '') {
                    $gtins[(int) $variant->getId()] = $value;
                    break;
                }
            }
        }

        return $gtins;
    }

    /**
     * Whether the install has a product attribute with the code.
     *
     * @param string $code
     * @return bool
     */
    private function exists(string $code): bool
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);

        return $attribute && $attribute->getId();
    }
}
