<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Migration;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;
use Magento\Framework\App\Config\Initial as InitialConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\Cms\HreflangGroup;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\CodeList;
use MageOS\Seo\Model\ResourceModel\MetaRobotsTagFlags;

/**
 * Carries MageOS_Hreflang's settings and CMS page links into this module, then switches that
 * module's head output off.
 *
 * The rule throughout: the store keeps publishing hreflang wherever it did. MageOS_Hreflang's
 * settings are carried over only where they were in effect; a value already set in this module is
 * kept — except an "off" that would leave a store view without hreflang once MageOS_Hreflang is
 * switched off; and a store view MageOS_Hreflang had off is never switched off here.
 *
 * | MageOS_Hreflang                 | This module                                 |
 * |---------------------------------|---------------------------------------------|
 * | web/seo/use_hreflangs           | hreflang/enabled, per store view            |
 * | web/seo/use_sitemap_hreflangs   | hreflang/sitemap_enabled, in sitemap.xml    |
 * | web/seo/hreflang (store view)   | hreflang/codes, validated as the admin does |
 * | web/seo/hreflang_xdefault_store | hreflang/xdefault_store_id, per website     |
 * | cms_page.meta_identifier        | the page's translation group                |
 *
 * Its alternates were always limited to the current website, which is already this module's
 * default, so there is nothing to carry for that.
 *
 * Its head output is switched off last, and only when it was on somewhere, so a failure part way
 * through leaves the store serving what it served. Switching off sets its head flag to 0 globally
 * and removes its website and store view values; nothing else of that module is touched, and what
 * was removed is reported so it can be restored by hand.
 *
 * Its sitemap flag is left as it is: the two modules cannot both write a sitemap's alternates.
 * Where this module's generator writes the sitemap, it replaces `generateXml()`, so
 * MageOS_Hreflang's rows are never written; where Magento's generator is selected, MageOS_Hreflang's
 * are the only alternates the sitemap gets, and switching them off would lose them.
 *
 * Safe to run when MageOS_Hreflang was never installed: there are no values and no column.
 */
class HreflangImporter
{
    private const THEIR_ENABLED  = 'web/seo/use_hreflangs';
    private const THEIR_SITEMAP  = 'web/seo/use_sitemap_hreflangs';
    private const THEIR_CODES    = 'web/seo/hreflang';
    private const THEIR_XDEFAULT = 'web/seo/hreflang_xdefault_store';
    private const THEIR_COLUMN   = 'meta_identifier';

    /**
     * @param ConfigCollectionFactory $configCollectionFactory
     * @param WriterInterface $configWriter
     * @param InitialConfig $initialConfig
     * @param StoreManagerInterface $storeManager
     * @param Json $json
     * @param CodeList $codeList
     * @param CmsPageCollectionFactory $cmsPageCollectionFactory
     * @param CmsPageConfigRepository $cmsPageConfigRepository
     * @param HreflangGroup $hreflangGroup
     * @param MetaRobotsTagFlags $cmsPageColumns Answers whether a cms_page column exists
     */
    public function __construct(
        private readonly ConfigCollectionFactory  $configCollectionFactory,
        private readonly WriterInterface          $configWriter,
        private readonly InitialConfig            $initialConfig,
        private readonly StoreManagerInterface    $storeManager,
        private readonly Json                     $json,
        private readonly CodeList                 $codeList,
        private readonly CmsPageCollectionFactory $cmsPageCollectionFactory,
        private readonly CmsPageConfigRepository  $cmsPageConfigRepository,
        private readonly HreflangGroup            $hreflangGroup,
        private readonly MetaRobotsTagFlags       $cmsPageColumns
    ) {
    }

    /**
     * Import everything, then switch MageOS_Hreflang's output off.
     *
     * @return array{
     *     enabled_store_ids: int[],
     *     sitemap_enabled: bool,
     *     x_default_website_ids: int[],
     *     codes_store_ids: int[],
     *     cms_pages_grouped: int,
     *     switched_off: array<int, array{path: string, scope: string, scope_id: int, value: string|null}>,
     *     notes: string[]
     * }
     */
    public function import(): array
    {
        $theirs = $this->storedValues([
            self::THEIR_ENABLED,
            self::THEIR_SITEMAP,
            self::THEIR_CODES,
            self::THEIR_XDEFAULT,
        ]);
        $ours = $this->storedValues([
            Config::XML_HREFLANG_ENABLED,
            Config::XML_HREFLANG_SITEMAP_ENABLED,
            Config::XML_HREFLANG_CODES,
            Config::XML_HREFLANG_XDEFAULT_STORE,
        ]);

        $notes    = [];
        $enabled  = [];
        $codes    = [];
        $websites = [];
        $sitemap  = false;

        foreach ($this->storeManager->getStores() as $store) {
            $storeId   = (int) $store->getId();
            $websiteId = (int) $store->getWebsiteId();

            $headOn    = $this->valueFor($theirs, self::THEIR_ENABLED, $websiteId, $storeId) === '1';
            $sitemapOn = $this->valueFor($theirs, self::THEIR_SITEMAP, $websiteId, $storeId) === '1';

            if ($headOn || $sitemapOn) {
                $websites[$websiteId] = true;
            }
            $sitemap = $sitemap || $sitemapOn;

            if (!$headOn) {
                continue;
            }
            if ($this->ourValueFor($ours, Config::XML_HREFLANG_ENABLED, $websiteId, $storeId) !== '1') {
                $this->configWriter->save(Config::XML_HREFLANG_ENABLED, '1', ScopeInterface::SCOPE_STORES, $storeId);
                $enabled[] = $storeId;
            }
            if ($this->importCodes($theirs, $ours, $websiteId, $storeId, $notes)) {
                $codes[] = $storeId;
            }
        }

        $sitemapEnabled = $sitemap
            && $this->ourValueFor($ours, Config::XML_HREFLANG_SITEMAP_ENABLED, 0, null) !== '1';
        if ($sitemapEnabled) {
            $this->configWriter->save(Config::XML_HREFLANG_SITEMAP_ENABLED, '1');
        }

        $xDefaults = [];
        foreach (array_keys($websites) as $websiteId) {
            if ($this->importXDefault($theirs, $ours, $websiteId, $notes)) {
                $xDefaults[] = $websiteId;
            }
        }

        $grouped = $this->importCmsGroups($notes);

        return [
            'enabled_store_ids'     => $enabled,
            'sitemap_enabled'       => $sitemapEnabled,
            'x_default_website_ids' => $xDefaults,
            'codes_store_ids'       => $codes,
            'cms_pages_grouped'     => $grouped,
            'switched_off'          => $this->wasOnAnywhere($theirs) ? $this->switchOff($theirs) : [],
            'notes'                 => $notes,
        ];
    }

    /**
     * Carry one store view's code list over, keeping only the codes this module accepts.
     *
     * @param array<string,array<string,array<int,string|null>>> $theirs
     * @param array<string,array<string,array<int,string|null>>> $ours
     * @param int $websiteId
     * @param int $storeId
     * @param string[] $notes
     * @return bool Whether codes were written
     */
    private function importCodes(array $theirs, array $ours, int $websiteId, int $storeId, array &$notes): bool
    {
        $stored = (string) $this->valueFor($theirs, self::THEIR_CODES, $websiteId, $storeId);
        if ($stored === '') {
            return false;
        }

        try {
            $rows = $this->json->unserialize($stored);
        } catch (\InvalidArgumentException) {
            $notes[] = \sprintf('Store view %d: its hreflang codes could not be read; not carried over.', $storeId);
            return false;
        }

        $entered = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && isset($row['hreflang'])) {
                $entered[] = (string) $row['hreflang'];
            }
        }

        $checked = $this->codeList->check($entered);
        if ($checked['rejected'] !== []) {
            $notes[] = \sprintf(
                'Store view %d: dropped hreflang codes this module does not accept: %s.',
                $storeId,
                implode(', ', $checked['rejected'])
            );
        }
        if ($checked['codes'] === []) {
            return false;
        }

        if ((string) $this->ourValueFor($ours, Config::XML_HREFLANG_CODES, $websiteId, $storeId) !== '') {
            $notes[] = \sprintf(
                'Store view %d: already has hreflang codes in this module; kept them instead of %s.',
                $storeId,
                implode(', ', $checked['codes'])
            );
            return false;
        }

        $this->configWriter->save(
            Config::XML_HREFLANG_CODES,
            implode(',', $checked['codes']),
            ScopeInterface::SCOPE_STORES,
            $storeId
        );

        return true;
    }

    /**
     * Carry a website's x-default store view over.
     *
     * @param array<string,array<string,array<int,string|null>>> $theirs
     * @param array<string,array<string,array<int,string|null>>> $ours
     * @param int $websiteId
     * @param string[] $notes
     * @return bool Whether it was written
     */
    private function importXDefault(array $theirs, array $ours, int $websiteId, array &$notes): bool
    {
        $xDefault = (string) $this->valueFor($theirs, self::THEIR_XDEFAULT, $websiteId, null);
        if ($xDefault === '' || $xDefault === '0') {
            return false;
        }

        if (!$this->storeExists((int) $xDefault)) {
            $notes[] = \sprintf(
                'Website %d: its x-default store view %s no longer exists; not carried over.',
                $websiteId,
                $xDefault
            );
            return false;
        }

        if ($this->ourValueFor($ours, Config::XML_HREFLANG_XDEFAULT_STORE, $websiteId, null) === $xDefault) {
            return false;
        }

        $this->configWriter->save(
            Config::XML_HREFLANG_XDEFAULT_STORE,
            $xDefault,
            ScopeInterface::SCOPE_WEBSITES,
            $websiteId
        );

        return true;
    }

    /**
     * Carry cms_page.meta_identifier over as each page's translation group.
     *
     * The values were never validated, so each is turned into a valid group ("About Us" becomes
     * "about-us"); a page that already has a group in this module keeps it.
     *
     * @param string[] $notes
     * @return int Pages given a group
     */
    private function importCmsGroups(array &$notes): int
    {
        if (!$this->cmsPageColumns->existOnCmsPage([self::THEIR_COLUMN])) {
            return 0;
        }

        $collection = $this->cmsPageCollectionFactory->create();
        $collection->addFieldToFilter(self::THEIR_COLUMN, ['neq' => '']);

        $grouped = 0;
        foreach ($collection as $page) {
            $pageId = (int) $page->getId();
            $value  = (string) $page->getData(self::THEIR_COLUMN);
            $group  = $this->hreflangGroup->slugify($value);

            if ($group === null) {
                $notes[] = \sprintf('CMS page %d: "%s" cannot be a translation group; skipped.', $pageId, $value);
                continue;
            }
            if ($this->cmsPageConfigRepository->getHreflangGroup($pageId) !== null) {
                continue;
            }
            if ($group !== $this->hreflangGroup->normalise($value)) {
                $notes[] = \sprintf('CMS page %d: "%s" imported as "%s".', $pageId, $value, $group);
            }

            $this->cmsPageConfigRepository->save($pageId, ['hreflang_group' => $group]);
            $grouped++;
        }

        return $grouped;
    }

    /**
     * Switch MageOS_Hreflang's head output off.
     *
     * @param array<string,array<string,array<int,string|null>>> $theirs
     * @return array<int, array{path: string, scope: string, scope_id: int, value: string|null}>
     */
    private function switchOff(array $theirs): array
    {
        $removed = [];
        foreach ([self::THEIR_ENABLED] as $path) {
            foreach ([ScopeInterface::SCOPE_WEBSITES, ScopeInterface::SCOPE_STORES] as $scope) {
                foreach ($theirs[$path][$scope] ?? [] as $scopeId => $value) {
                    $this->configWriter->delete($path, $scope, $scopeId);
                    $removed[] = ['path' => $path, 'scope' => $scope, 'scope_id' => $scopeId, 'value' => $value];
                }
            }

            $removed[] = [
                'path'     => $path,
                'scope'    => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                'scope_id' => 0,
                'value'    => $theirs[$path][ScopeConfigInterface::SCOPE_TYPE_DEFAULT][0] ?? null,
            ];
            $this->configWriter->save($path, '0');
        }

        return $removed;
    }

    /**
     * Whether MageOS_Hreflang had its head output on at any scope.
     *
     * @param array<string,array<string,array<int,string|null>>> $theirs
     * @return bool
     */
    private function wasOnAnywhere(array $theirs): bool
    {
        foreach ([self::THEIR_ENABLED] as $path) {
            foreach ($theirs[$path] ?? [] as $values) {
                if (\in_array('1', $values, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every stored value of the given paths, by path, scope and scope ID.
     *
     * Read from core_config_data rather than through the scope config: the inheritance is needed
     * scope by scope, and the scope config would answer from whatever was cached before the
     * upgrade began.
     *
     * @param string[] $paths
     * @return array<string,array<string,array<int,string|null>>>
     */
    private function storedValues(array $paths): array
    {
        $collection = $this->configCollectionFactory->create();
        $collection->addFieldToFilter('path', ['in' => $paths]);

        $values = [];
        foreach ($collection as $row) {
            $value = $row->getData('value');
            $values[(string) $row->getData('path')][(string) $row->getData('scope')][(int) $row->getData('scope_id')]
                = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /**
     * A stored value as a store view (or, with a null store ID, a website) inherits it.
     *
     * @param array<string,array<string,array<int,string|null>>> $values
     * @param string $path
     * @param int $websiteId
     * @param int|null $storeId
     * @return string|null
     */
    private function valueFor(array $values, string $path, int $websiteId, ?int $storeId): ?string
    {
        $store = $storeId === null ? null : ($values[$path][ScopeInterface::SCOPE_STORES][$storeId] ?? null);

        return $store
            ?? $values[$path][ScopeInterface::SCOPE_WEBSITES][$websiteId]
            ?? $values[$path][ScopeConfigInterface::SCOPE_TYPE_DEFAULT][0]
            ?? null;
    }

    /**
     * One of this module's values as it is inherited, falling back to its config.xml default.
     *
     * @param array<string,array<string,array<int,string|null>>> $values
     * @param string $path
     * @param int $websiteId
     * @param int|null $storeId
     * @return string|null
     */
    private function ourValueFor(array $values, string $path, int $websiteId, ?int $storeId): ?string
    {
        $value = $this->valueFor($values, $path, $websiteId, $storeId);
        if ($value !== null) {
            return $value;
        }

        $default = $this->initialConfig->getData(ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        foreach (explode('/', $path) as $part) {
            if (!\is_array($default) || !\array_key_exists($part, $default)) {
                return null;
            }
            $default = $default[$part];
        }

        return \is_scalar($default) ? (string) $default : null;
    }

    /**
     * Whether a store view with the ID exists.
     *
     * @param int $storeId
     * @return bool
     */
    private function storeExists(int $storeId): bool
    {
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getId() === $storeId) {
                return true;
            }
        }

        return false;
    }
}
