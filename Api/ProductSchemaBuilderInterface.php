<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Builds the Product node for one product schema template (Apparel, Food, …), which a category
 * selects. Register a new template in the schema builder pool via your own di.xml.
 *
 * @api
 */
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
     * Translated: return `(string) __('…')`. It is shown in the admin's language in the template
     * selects, and in the store view's language in /llms-full.txt.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Return the optional fields this template exposes, as field code => label.
     *
     * The category SEO tab lists them in its Enabled Optional Fields multiselect. Labels are
     * translated, as getLabel() is; the codes are not.
     *
     * @return array<string, string>
     */
    public function getAvailableFields(): array;

    /**
     * Build and return the Product schema node.
     *
     * $enabledFields  — optional field codes the category editor has switched on.
     * $overrides      — per-category or per-product hard-coded field values.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string[] $enabledFields
     * @param mixed[] $overrides
     * @return mixed[]
     */
    public function build(
        ProductInterface $product,
        array $enabledFields,
        array $overrides
    ): array;
}
