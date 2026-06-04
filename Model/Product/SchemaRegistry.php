<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

/**
 * Request-scoped registry that holds the product schema node being assembled
 * for the current page. Allows the variant enricher (ProductVariantUrlSeo bridge)
 * to mutate the base node in-place without producing a duplicate Product schema.
 */
class SchemaRegistry
{
    /** @var array<string, mixed>|null */
    private ?array $productSchema = null;

    /**
     * Store the assembled product schema node.
     *
     * @param mixed[] $schema
     * @return void
     */
    public function set(array $schema): void
    {
        $this->productSchema = $schema;
    }

    /**
     * Retrieve the current product schema node, or null if not yet set.
     *
     * @return mixed[]|null
     */
    public function get(): ?array
    {
        return $this->productSchema;
    }

    /**
     * Merge additional fields into the stored product schema node.
     *
     * Used by the variant enricher to overlay variant-specific values.
     *
     * @param mixed[] $fields
     * @return void
     */
    public function merge(array $fields): void
    {
        if ($this->productSchema === null) {
            $this->productSchema = $fields;
            return;
        }
        $this->productSchema = array_replace_recursive($this->productSchema, $fields);
    }

    /**
     * Merge fields into a nested key (e.g. 'offers').
     *
     * @param string $key
     * @param mixed[] $fields
     * @return void
     */
    public function mergeNested(string $key, array $fields): void
    {
        if ($this->productSchema === null) {
            return;
        }
        $existing = $this->productSchema[$key] ?? [];
        $this->productSchema[$key] = array_merge($existing, $fields);
    }

    /**
     * Return whether a product schema has been stored.
     *
     * @return bool
     */
    public function has(): bool
    {
        return $this->productSchema !== null;
    }
}
