<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag;

use Magento\Framework\View\Layout;
use MageOS\Seo\Api\MetaTagProviderInterface;

class Compositor
{
    /**
     * @param Layout $layout
     * @param array<mixed> $providers
     */
    public function __construct(
        private readonly Layout $layout,
        private readonly array  $providers = []
    ) {
    }

    /**
     * Collect all meta tag definitions from matching providers.
     *
     * @return mixed[]
     */
    public function getMetaTags(): array
    {
        $activeHandles = $this->layout->getUpdate()->getHandles();
        $tags = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof MetaTagProviderInterface) {
                continue;
            }
            if (!$this->handlesMatch($provider->getHandles(), $activeHandles)) {
                continue;
            }
            foreach ($provider->getMetaTags() as $tag) {
                if (!empty($tag['content'])) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * Check if any of the provider's handles match the current active handles.
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
