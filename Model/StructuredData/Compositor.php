<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData;

use Magento\Framework\View\LayoutInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Pool\HandleMatcher;
use MageOS\Seo\Model\Product\SchemaRegistry;

class Compositor
{
    /**
     * @param LayoutInterface $layout
     * @param SchemaRegistry $schemaRegistry
     * @param HandleMatcher $handleMatcher
     * @param array<mixed> $providers
     */
    public function __construct(
        private readonly LayoutInterface         $layout,
        private readonly SchemaRegistry $schemaRegistry,
        private readonly HandleMatcher  $handleMatcher,
        private readonly array          $providers = []
    ) {
    }

    /**
     * Collect all schemas from matching providers and return as a JSON string.
     *
     * Returns an empty string if structured data is disabled or no schemas produced.
     *
     * The product schema is handled specially: ProductSchemaProvider stores the
     * node in SchemaRegistry and returns [], so a provider that runs after it can still
     * adjust the node there. After all providers have run, we read the final state
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
            if (!$this->handleMatcher->matches($provider->getHandles(), $activeHandles)) {
                continue;
            }
            foreach ($provider->getSchemas() as $schema) {
                if (!empty($schema)) {
                    $schemas[] = $schema;
                }
            }
        }

        // Append the product schema from the registry after all providers have run, so any
        // provider's change to it is included regardless of provider execution order.
        $productSchema = $this->schemaRegistry->get();
        if ($productSchema !== null && !empty($productSchema)) {
            $schemas[] = $productSchema;
        }

        if (empty($schemas)) {
            return '';
        }

        // JSON_HEX_TAG/JSON_HEX_AMP encode <, > and & as \uXXXX so neither </script>
        // nor <!-- can ever appear inside the inline <script> payload, keeping the
        // output valid JSON (a post-encode str_replace cannot guarantee that).
        $json = json_encode(
            $schemas,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return '';
        }

        return $json;
    }
}
