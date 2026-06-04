<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use Magento\Catalog\Api\Data\ProductInterface;

interface ProductSchemaBuilderInterface
{
    /**
     * The template code this builder handles (e.g. "Food", "Apparel").
     *
     * Must match the value stored in the category schema_template config.
     *
     * @return string
     */
    public function getTemplateCode(): string;

    /**
     * Human-readable label for admin dropdowns.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Return the list of optional field codes this template exposes.
     *
     * These are shown as a multiselect in the category SEO tab.
     *
     * @return string[]
     */
    public function getAvailableFields(): array;

    /**
     * Build and return the Product schema node.
     *
     * $enabledFields  — optional field codes the category editor has switched on.
     * $overrides      — per-category or per-product hard-coded field values.
     * $variantData    — decoded variant slug data array (empty when no variant URL active).
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string[] $enabledFields
     * @param mixed[] $overrides
     * @param mixed[] $variantData
     * @return mixed[]
     */
    public function build(
        ProductInterface $product,
        array $enabledFields,
        array $overrides,
        array $variantData
    ): array;
}
