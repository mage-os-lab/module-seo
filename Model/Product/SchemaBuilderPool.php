<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Api\ProductSchemaBuilderInterface;

class SchemaBuilderPool
{
    /**
     * @param array<mixed> $builders
     */
    public function __construct(
        private readonly array $builders = []
    ) {
    }

    /**
     * Build a product schema node using the builder registered for $templateCode.
     *
     * Falls back to GenericProduct if the requested template is not registered.
     *
     * @param string $templateCode
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string[] $enabledFields
     * @param mixed[] $overrides
     * @param mixed[] $variantData
     * @return mixed[]
     */
    public function build(
        string           $templateCode,
        ProductInterface $product,
        array            $enabledFields,
        array            $overrides,
        array            $variantData
    ): array {
        $builder = $this->builders[$templateCode] ?? $this->builders['GenericProduct'] ?? null;

        if ($builder === null) {
            return [];
        }

        return $builder->build($product, $enabledFields, $overrides, $variantData);
    }

    /**
     * Return all registered template codes and labels for admin dropdowns.
     *
     * @return array<string, string>
     */
    public function getAvailableTemplates(): array
    {
        $templates = [];
        foreach ($this->builders as $builder) {
            if ($builder instanceof ProductSchemaBuilderInterface) {
                $templates[$builder->getTemplateCode()] = $builder->getLabel();
            }
        }
        return $templates;
    }

    /**
     * Return available optional fields for a given template code.
     *
     * @param string $templateCode
     * @return string[]
     */
    public function getAvailableFields(string $templateCode): array
    {
        $builder = $this->builders[$templateCode] ?? null;
        if ($builder instanceof ProductSchemaBuilderInterface) {
            return $builder->getAvailableFields();
        }
        return [];
    }
}
