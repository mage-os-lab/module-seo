<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Log\OccasionalLog;
use MageOS\Seo\Model\Store\CanonicalBaseUrl;

/**
 * Request-scoped map of active store views to their base URL and the hreflang codes they claim.
 *
 * A store view claims the codes set for it under **hreflang/codes**, or failing that the one its
 * locale gives. A locale is not a target region: one store can serve several (es-MX, es-AR, es-CL),
 * and a store whose Magento locale cannot name its region — or that shares a locale with another —
 * needs saying explicitly.
 *
 * Every code is checked as the admin checks a typed one (Model\Hreflang\CodeList), whatever set it:
 * config.php, env.php, `config:set` and a database write skip the admin (issue #29). A code that
 * fails is left out and logged, at most once every five minutes per store view and code.
 *
 * Store views that are inactive, excluded, or have hreflang off (issue #28) are filtered here, before
 * they can claim a code, so the head, the sitemap, the language-only tags and x-default never see
 * them. Built once per request for each website it is asked about, and memoised: which store views
 * are in the map depends on the current store's website, and a cron run generating sitemaps moves
 * from one website's store views to another's in one process. A queue consumer resets it before
 * each message (Model\Rebuild\BuildFreshness).
 *
 * Base URLs are the store views' canonical ones (Model\Store\CanonicalBaseUrl), the same the
 * sitemap's `<loc>` uses — not whatever scheme the current request happens to have.
 */
class StoreLocaleMap implements ResetAfterRequestInterface
{
    /**
     * Built maps, keyed by the website they were limited to, or "all".
     *
     * @var array<int|string,array<int,array{base_url:string,codes:string[]}>>
     */
    private array $maps = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $seoConfig
     * @param CodeValidator $codeValidator
     * @param CanonicalBaseUrl $canonicalBaseUrl
     * @param CodeList $codeList
     * @param OccasionalLog $occasionalLog
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config                $seoConfig,
        private readonly CodeValidator         $codeValidator,
        private readonly CanonicalBaseUrl      $canonicalBaseUrl,
        private readonly CodeList              $codeList,
        private readonly OccasionalLog         $occasionalLog
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
        $websiteId = $this->seoConfig->isHreflangSameWebsiteOnly()
            ? (int) $this->storeManager->getStore()->getWebsiteId()
            : null;

        return $this->maps[$websiteId === null ? 'all' : (string) $websiteId] ??= $this->build($websiteId);
    }

    /**
     * Build the map, limited to one website's store views or not.
     *
     * @param int|null $websiteId
     * @return array<int,array{base_url:string,codes:string[]}>
     */
    private function build(?int $websiteId): array
    {
        $excluded = $this->seoConfig->getHreflangExcludedStoreIds();

        // Sort by actual store ID (not array keys) so the dedupe winner below is
        // deterministic regardless of how the store list is keyed.
        $stores = array_values($this->storeManager->getStores());
        usort($stores, static fn ($a, $b): int => (int) $a->getId() <=> (int) $b->getId());

        $map     = [];
        $claimed = [];

        /** @var Store $store */
        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            // Before claiming codes: a store view with hreflang off links back to nothing, so it is
            // no alternate, and must not take a shared code from one that is.
            if (!$store->getIsActive()
                || \in_array($storeId, $excluded, true)
                || !$this->seoConfig->isHreflangEnabled($storeId)
            ) {
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
                'base_url' => $this->canonicalBaseUrl->of($store),
                'codes'    => $codes,
            ];
        }

        return $map;
    }

    /**
     * The codes a store view claims.
     *
     * The valid ones of its configured list, else its locale's, if that is valid.
     *
     * @param int $storeId
     * @return string[]
     */
    private function codesFor(int $storeId): array
    {
        $configured = $this->checked($storeId, $this->seoConfig->getHreflangCodes($storeId));
        if ($configured !== []) {
            return $configured;
        }

        $localeCode = $this->seoConfig->getLocaleCode($storeId);

        return $localeCode === '' ? [] : $this->checked($storeId, [$localeCode]);
    }

    /**
     * The codes that pass the admin's check, normalised and each once; the others are logged.
     *
     * @param int $storeId
     * @param string[] $codes
     * @return string[]
     */
    private function checked(int $storeId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $checked = $this->codeList->check($codes);
        foreach ($checked['rejected'] as $code) {
            $this->logRejected($storeId, (string) $code);
        }

        return $checked['codes'];
    }

    /**
     * Log a code the check rejected, at most once every five minutes per store view and code.
     *
     * A map is built on every uncached page, and the code stays wrong until someone changes the
     * configuration: once in a while is enough to find it.
     *
     * @param int $storeId
     * @param string $code
     * @return void
     */
    private function logRejected(int $storeId, string $code): void
    {
        $this->occasionalLog->warning(
            'hreflang code' . "\0" . $storeId . "\0" . $code,
            \sprintf(
                'MageOS_Seo: store view %d\'s hreflang code "%s" is not valid, so it is left out. Use a '
                . 'two-letter language, optionally a four-letter script and an ISO 3166-1 region, each '
                . 'code once — for example en, en-GB or zh-Hant-TW.',
                $storeId,
                $code
            )
        );
    }

    /**
     * Drop the memoised maps, so the next getMap() reads the configuration again.
     *
     * The maps are kept per website, so moving between store views no longer needs this; a
     * configuration change within the process does.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->maps = [];
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
