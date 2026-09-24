<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\HreflangResolverInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Pool\HandleMatcher;

/**
 * Builds the full hreflang link set for the current page.
 *
 * Picks the first matching resolver that returns region links, then delegates to AlternateBuilder
 * to append automatic language-only tags and the configured x-default (and to drop single-store
 * pages).
 *
 * A set that does not include the current store view is not declared at all (SelfReference): an
 * excluded store view, for one, has no link of its own, and its pages used to list every other
 * store view but themselves — a set Google discards.
 */
class ResolverPool
{
    /**
     * Collaborators are required: the ObjectManager passes the default for optional
     * constructor parameters unless di.xml configures them per consumer, so optional
     * collaborators with "?? new X()" fallbacks silently bypass DI configuration.
     *
     * @param LayoutInterface $layout
     * @param Config $seoConfig
     * @param HandleMatcher $handleMatcher
     * @param AlternateBuilder $alternateBuilder
     * @param StoreManagerInterface $storeManager
     * @param SelfReference $selfReference
     * @param array<mixed> $resolvers
     */
    public function __construct(
        private readonly LayoutInterface       $layout,
        private readonly Config                $seoConfig,
        private readonly HandleMatcher         $handleMatcher,
        private readonly AlternateBuilder      $alternateBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly SelfReference         $selfReference,
        private readonly array                 $resolvers = []
    ) {
    }

    /**
     * Return the full ordered hreflang link set for the current page.
     *
     * @return array<int, array{hreflang: string, url: string}>
     */
    public function getLinks(): array
    {
        if (!$this->seoConfig->isHreflangEnabled()) {
            return [];
        }

        $links = $this->resolveRegionLinks();
        if (!$this->selfReference->includes($links, (int) $this->storeManager->getStore()->getId())) {
            return [];
        }

        return $this->alternateBuilder->build($links);
    }

    /**
     * Find the first matching resolver that yields region links.
     *
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    private function resolveRegionLinks(): array
    {
        $activeHandles = $this->layout->getUpdate()->getHandles();

        foreach ($this->resolvers as $resolver) {
            if (!$resolver instanceof HreflangResolverInterface) {
                continue;
            }
            if (!$this->handleMatcher->matches($resolver->getHandles(), $activeHandles)) {
                continue;
            }
            $links = $resolver->getLinks();
            if (!empty($links)) {
                return $links;
            }
        }

        return [];
    }
}
