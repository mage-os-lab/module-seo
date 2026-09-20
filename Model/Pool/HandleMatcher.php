<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Pool;

/**
 * Shared layout-handle matching for provider pools.
 *
 * A provider declares the layout handles it applies to via getHandles(). This collaborator
 * decides whether a provider runs on the current page: the wildcard '*' matches every page,
 * otherwise at least one provider handle must be present in the active layout handles.
 *
 * Extracted from the duplicated handlesMatch() logic in the StructuredData, MetaTag and
 * PageTitle compositors so every pool shares one implementation (and one unit test).
 *
 * Denied handles exist because '*' was reaching pages nobody had in mind. Cart, checkout,
 * customer account and login-as-customer pages are uncacheable, so a wildcard provider's work —
 * for the Organisation providers, up to three queries each — is repeated on every single
 * request, to emit Organization, WebSite and Speakable nodes onto a form. The deny list is
 * declared in di.xml so an integration can extend or empty it.
 *
 * It suppresses **wildcard** providers only. A provider that names one of these handles in
 * getHandles() has asked to be there and still runs; the point is to stop providers landing on
 * pages they never claimed, not to overrule a deliberate one — including a third party's.
 */
class HandleMatcher
{
    /**
     * @param string[] $deniedHandles Exact handles, or a prefix ending in '*'
     */
    public function __construct(
        private readonly array $deniedHandles = []
    ) {
    }

    /**
     * Whether a provider's handles match the current page's active layout handles.
     *
     * @param string[] $providerHandles
     * @param string[] $activeHandles
     * @return bool
     */
    public function matches(array $providerHandles, array $activeHandles): bool
    {
        if (\in_array('*', $providerHandles, true)) {
            return !$this->isDenied($activeHandles);
        }

        return !empty(array_intersect($providerHandles, $activeHandles));
    }

    /**
     * Whether the page carries a handle wildcard providers are kept off.
     *
     * @param string[] $activeHandles
     * @return bool
     */
    private function isDenied(array $activeHandles): bool
    {
        foreach ($this->deniedHandles as $denied) {
            // A trailing '*' matches a family of handles. Listing them exactly is brittle: a
            // Magento release adding, say, another checkout handle would quietly reopen the gap.
            if (str_ends_with($denied, '*')) {
                $prefix = substr($denied, 0, -1);
                foreach ($activeHandles as $handle) {
                    if (str_starts_with($handle, $prefix)) {
                        return true;
                    }
                }
                continue;
            }

            if (\in_array($denied, $activeHandles, true)) {
                return true;
            }
        }

        return false;
    }
}
