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
     * An override for one of the template's own fields (getAvailableFields()) turns that field on,
     * as documented: "any key present in the override JSON is applied regardless of whether the
     * field is listed in the category's enabled fields". The template then builds it in its own
     * shape — a Brand node, a PeopleAudience, an additionalProperty entry — rather than the raw
     * value being set on the node. Keys the template does not know are left to the builder's
     * applyOverrides(), which sets them as given.
     *
     * @param string $templateCode
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string[] $enabledFields
     * @param mixed[] $overrides
     * @return mixed[]
     */
    public function build(
        string           $templateCode,
        ProductInterface $product,
        array            $enabledFields,
        array            $overrides
    ): array {
        $builder = $this->builders[$templateCode] ?? $this->builders['GenericProduct'] ?? null;

        if ($builder === null) {
            return [];
        }

        $overridden = array_keys(array_filter(
            array_intersect_key($overrides, $builder->getAvailableFields()),
            static fn ($value): bool => $value !== null && $value !== ''
        ));
        $enabledFields = array_values(array_unique([...$enabledFields, ...$overridden]));

        return $builder->build($product, $enabledFields, $overrides);
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
