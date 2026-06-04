<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageTitle;

use Magento\Framework\View\Layout;
use MageOS\Seo\Api\PageTitleProviderInterface;

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
     * Return the winning page title, or an empty string if no provider contributes one.
     *
     * The non-empty provider with the highest sortOrder wins.
     *
     * @return string
     */
    public function getTitle(): string
    {
        $activeHandles = $this->layout->getUpdate()->getHandles();

        $candidates = [];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof PageTitleProviderInterface) {
                continue;
            }
            if (!$this->handlesMatch($provider->getHandles(), $activeHandles)) {
                continue;
            }
            $title = $provider->getTitle();
            if ($title !== '') {
                $candidates[] = ['title' => $title, 'sort' => $provider->getSortOrder()];
            }
        }

        if (empty($candidates)) {
            return '';
        }

        usort($candidates, static fn (array $a, array $b) => $b['sort'] <=> $a['sort']);

        return $candidates[0]['title'];
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
