<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData;

use Magento\Framework\View\Layout;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Product\SchemaRegistry;

class Compositor
{
    /**
     * @param Layout $layout
     * @param SchemaRegistry $schemaRegistry
     * @param array<mixed> $providers
     */
    public function __construct(
        private readonly Layout         $layout,
        private readonly SchemaRegistry $schemaRegistry,
        private readonly array          $providers = []
    ) {
    }

    /**
     * Collect all schemas from matching providers and return as a JSON string.
     *
     * Returns an empty string if structured data is disabled or no schemas produced.
     *
     * The product schema is handled specially: ProductSchemaProvider stores the
     * base schema in SchemaRegistry and returns []. The VariantSchemaEnricher then
     * mutates the registry. After all providers have run, we read the final state
     * from the registry and append it. This avoids any reference chain complexity.
     *
     * @return string
     */
    public function render(): string
    {
        $activeHandles = $this->layout->getUpdate()->getHandles();
        $schemas = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof StructuredDataProviderInterface) {
                continue;
            }
            if (!$this->handlesMatch($provider->getHandles(), $activeHandles)) {
                continue;
            }
            foreach ($provider->getSchemas() as $schema) {
                if (!empty($schema)) {
                    $schemas[] = $schema;
                }
            }
        }

        // Append the product schema from the registry after all providers have run.
        // This ensures the enricher's mutations (hasVariant, offers enrichment) are
        // included regardless of provider execution order.
        $productSchema = $this->schemaRegistry->get();
        if ($productSchema !== null && !empty($productSchema)) {
            $schemas[] = $productSchema;
        }

        if (empty($schemas)) {
            return '';
        }

        $json = json_encode(
            $schemas,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            return '';
        }

        // XSS protection: prevent </script> injection and HTML comment breaking.
        $json = str_replace(['</', '<!--'], ['<\/', '<\!--'], $json);

        return $json;
    }

    /**
     * Check whether any provider handle matches the current page's active handles.
     *
     * @param string[] $providerHandles
     * @param string[] $activeHandles
     * @return bool
     */
    private function handlesMatch(array $providerHandles, array $activeHandles): bool
    {
        if (\in_array('*', $providerHandles, true)) {
            return true;
        }
        return !empty(array_intersect($providerHandles, $activeHandles));
    }
}
