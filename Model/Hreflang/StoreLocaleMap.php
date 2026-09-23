<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;

/**
 * Request-scoped map of active store views to their base URL and the hreflang codes they claim.
 *
 * A store view claims the codes set for it under **hreflang/codes**, or failing that the one its
 * locale gives. A locale is not a target region: one store can serve several (es-MX, es-AR, es-CL),
 * and a store whose Magento locale cannot name its region — or that shares a locale with another —
 * needs saying explicitly.
 *
 * Excluded and inactive stores are filtered here so downstream resolvers and the sitemap never see
 * them. Built once per request and memoised.
 */
class StoreLocaleMap implements ResetAfterRequestInterface
{
    /**
     * @var array<int, array{base_url: string, codes: string[]}>|null
     */
    private ?array $map = null;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $seoConfig
     * @param CodeValidator $codeValidator
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly Config                $seoConfig,
        private readonly CodeValidator         $codeValidator
    ) {
    }

    /**
     * Return store_id => [base_url, codes] for all eligible store views.
     *
     * Scoped to the current website by default (config: hreflang/same_website_only). Two alternates
     * with the same hreflang value are invalid, so a code already claimed is skipped — deterministically,
     * the lowest store ID keeps it. That happens **per code**: a store losing one code keeps its
     * others, and drops out only when every code it claims is taken. It used to be per store, so two
     * store views sharing a locale lost the second one entirely.
     *
     * @return array<int, array{base_url: string, codes: string[]}>
     */
    public function getMap(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $excluded  = $this->seoConfig->getHreflangExcludedStoreIds();
        $websiteId = $this->seoConfig->isHreflangSameWebsiteOnly()
            ? (int) $this->storeManager->getStore()->getWebsiteId()
            : null;

        // Sort by actual store ID (not array keys) so the dedupe winner below is
        // deterministic regardless of how the store list is keyed.
        $stores = array_values($this->storeManager->getStores());
        usort($stores, static fn ($a, $b): int => (int) $a->getId() <=> (int) $b->getId());

        $map     = [];
        $claimed = [];

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            if (!$store->getIsActive() || \in_array($storeId, $excluded, true)) {
                continue;
            }
            if ($websiteId !== null && (int) $store->getWebsiteId() !== $websiteId) {
                continue;
            }

            $codes = [];
            foreach ($this->codesFor($storeId) as $code) {
                if (isset($claimed[$code])) {
                    continue;
                }
                $claimed[$code] = true;
                $codes[]        = $code;
            }

            if ($codes === []) {
                continue;
            }

            $map[$storeId] = [
                'base_url' => rtrim((string) $store->getBaseUrl(), '/'),
                'codes'    => $codes,
            ];
        }

        $this->map = $map;
        return $this->map;
    }

    /**
     * The codes a store view claims: its configured list, else the one its locale gives.
     *
     * @param int $storeId
     * @return string[]
     */
    private function codesFor(int $storeId): array
    {
        $configured = $this->seoConfig->getHreflangCodes($storeId);
        if ($configured !== []) {
            return $configured;
        }

        $localeCode = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $localeCode === '' ? [] : [$this->codeValidator->normalise($localeCode)];
    }

    /**
     * Drop the memoised map so the next getMap() rebuilds for the current store scope.
     *
     * Needed by cron feed generation, which iterates stores under environment
     * emulation within a single process.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->map = null;
    }

    /**
     * Drop the memoised map between worker-mode requests (delegates to reset()).
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->reset();
    }

    /**
     * Convert a Magento locale code to a BCP 47 tag ('en_GB' → 'en-GB').
     *
     * Kept for callers outside this class; the one implementation lives in CodeValidator, which the
     * configured codes go through as well, so a locale and a typed code normalise identically.
     *
     * @param string $magentoLocale
     * @return string
     */
    public function formatLocale(string $magentoLocale): string
    {
        return $this->codeValidator->normalise($magentoLocale);
    }

    /**
     * Extract the base language from a BCP 47 locale ('en-GB' → 'en').
     *
     * @param string $bcp47Locale
     * @return string
     */
    public function extractLanguage(string $bcp47Locale): string
    {
        return explode('-', $bcp47Locale)[0];
    }
}
