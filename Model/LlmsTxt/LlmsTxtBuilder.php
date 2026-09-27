<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsTxt;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Organisation\ContactEmail;
use MageOS\Seo\Model\Product\SchemaBuilderPool;

/**
 * Builds the /llms.txt and /llms-full.txt document bodies.
 *
 * These documents are served as plain text at known URLs so LLM crawlers and
 * AI commerce agents can understand the site structure without full crawl cycles.
 *
 * Extended vendor and category data is injected via provider arrays registered
 * in di.xml — allowing SellersSeo (and any future bridge) to contribute content
 * without coupling this class to those modules.
 *
 * The locale is the store view's configured one (Config::getLocaleCode()); the line is left out
 * when there is none. The AI contact is Organisation\ContactEmail's, which ai-plugin.json shares;
 * the section is left out when there is none.
 */
class LlmsTxtBuilder
{
    /**
     * @param OrganisationRepositoryInterface $organisationRepository
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param SchemaBuilderPool $builderPool
     * @param Config $seoConfig
     * @param ContactEmail $contactEmail
     * @param \MageOS\Seo\Model\LlmsTxt\SectionProviderInterface[] $sectionProviders
     */
    public function __construct(
        private readonly OrganisationRepositoryInterface $organisationRepository,
        private readonly StoreManagerInterface           $storeManager,
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly CategoryCollectionFactory       $categoryCollectionFactory,
        private readonly SchemaBuilderPool               $builderPool,
        private readonly Config                          $seoConfig,
        private readonly ContactEmail                    $contactEmail,
        private readonly array                           $sectionProviders = []
    ) {
    }

    /**
     * Build the concise /llms.txt document.
     *
     * @return string
     */
    public function buildConcise(): string
    {
        /** @var \Magento\Store\Model\Store $store */
        $store     = $this->storeManager->getStore();
        $storeId   = (int) $store->getId();
        $websiteId = (int) $this->storeManager->getWebsite()->getId();
        $org       = $this->organisationRepository->getForScope($storeId, $websiteId);
        $baseUrl   = rtrim((string) $store->getBaseUrl(), '/');
        $name      = $org->getName() ?: (string) $store->getName();

        $lines = [];

        // Header
        $lines[] = "# {$name}";
        if ($org->getDescription() !== '') {
            $lines[] = '> ' . $org->getDescription();
        }
        $lines[] = "> Base URL: {$baseUrl}";
        $lines   = [...$lines, ...$this->localeLine($storeId)];
        $lines[] = '';

        // Key URLs
        $lines[] = '## Key URLs';
        $lines[] = "- Home: {$baseUrl}";
        $lines[] = "- Sitemap: {$baseUrl}/sitemap.xml";
        $lines[] = "- Search: {$baseUrl}/catalogsearch/result?q={query}";
        $lines[] = '';

        // Schema types
        $templates = $this->builderPool->getAvailableTemplates();
        if (!empty($templates)) {
            $lines[] = '## Schema types available on this site';
            $lines[] = implode(', ', array_keys($templates));
            $lines[] = '';
        }

        // Section providers (concise mode)
        foreach ($this->sectionProviders as $provider) {
            if ($provider instanceof SectionProviderInterface) {
                $section = $provider->getConciseSection();
                if ($section !== '') {
                    $lines[] = $section;
                    $lines[] = '';
                }
            }
        }

        // AI contact
        $contactEmail = $this->contactEmail->get();
        if ($contactEmail !== '') {
            $lines[] = '## AI Contact';
            $lines[] = $contactEmail;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Build the extended /llms-full.txt document.
     *
     * @return string
     */
    public function buildFull(): string
    {
        /** @var \Magento\Store\Model\Store $store */
        $store     = $this->storeManager->getStore();
        $storeId   = (int) $store->getId();
        $websiteId = (int) $this->storeManager->getWebsite()->getId();
        $org       = $this->organisationRepository->getForScope($storeId, $websiteId);
        $baseUrl   = rtrim((string) $store->getBaseUrl(), '/');
        $name      = $org->getName() ?: (string) $store->getName();

        $lines = [];

        // Header
        $lines[] = "# {$name}";
        if ($org->getDescription() !== '') {
            $lines[] = '> ' . $org->getDescription();
        }
        $lines[] = "> Base URL: {$baseUrl}";
        $lines   = [...$lines, ...$this->localeLine($storeId)];

        $socials = $org->getSocialProfiles();
        if (!empty($socials)) {
            $lines[] = '> Social: ' . implode(' | ', $socials);
        }
        $lines[] = '';

        // Key URLs
        $lines[] = '## Key URLs';
        $lines[] = "- Home: {$baseUrl}";
        $lines[] = "- Sitemap: {$baseUrl}/sitemap.xml";
        $lines[] = "- Search: {$baseUrl}/catalogsearch/result?q={query}";
        $lines[] = '';

        // Schema types in use
        $templates = $this->builderPool->getAvailableTemplates();
        if (!empty($templates)) {
            $lines[] = '## Schema types in use';
            $schemaTypes = [
                'Organization', 'WebSite', 'CollectionPage', 'BreadcrumbList', 'ItemList',
            ];
            foreach (array_keys($templates) as $templateCode) {
                // Map template codes to their schema.org @type
                $typeMap = [
                    // Book/Software/ArtAndCraft emit multi-type ["Product", X] nodes;
                    // listed here by their distinguishing secondary type.
                    'Book'             => 'Book',
                    'Software'         => 'SoftwareApplication',
                    'ArtAndCraft'      => 'VisualArtwork',
                ];
                $schemaTypes[] = $typeMap[$templateCode] ?? 'Product';
            }
            $lines[] = implode(', ', array_unique($schemaTypes));
            $lines[] = '';

            $lines[] = '## Available product schema templates';
            foreach ($templates as $code => $label) {
                $lines[] = "- {$code}: {$label}";
            }
            $lines[] = '';
        }

        // Category tree
        $lines[] = $this->buildCategorySection($baseUrl);

        // Section providers (full mode — vendors, etc.)
        foreach ($this->sectionProviders as $provider) {
            if ($provider instanceof SectionProviderInterface) {
                $section = $provider->getFullSection();
                if ($section !== '') {
                    $lines[] = $section;
                    $lines[] = '';
                }
            }
        }

        // AI contact
        $contactEmail = $this->contactEmail->get();
        if ($contactEmail !== '') {
            $lines[] = '## AI Contact';
            $lines[] = "Preferred contact for automated queries: {$contactEmail}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * The header's locale line, or none when the store view has no locale configured.
     *
     * @param int $storeId
     * @return string[]
     */
    private function localeLine(int $storeId): array
    {
        $locale = $this->seoConfig->getLocaleCode($storeId);

        return $locale === '' ? [] : ["> Locale: {$locale}"];
    }

    /**
     * Build the category tree section for the current store's tree only.
     *
     * @param string $baseUrl
     * @return string
     */
    private function buildCategorySection(string $baseUrl): string
    {
        $lines = ['## Category Tree'];

        try {
            /** @var \Magento\Store\Model\Store $store */
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $rootId  = (int) $store->getRootCategoryId();

            $collection = $this->categoryCollectionFactory->create();
            // Store scoping is essential: without setStoreId + the root-path filter
            // this would list every website's categories (including hidden B2B or
            // staging trees) and pair their url_path with this store's base URL.
            $collection->setStoreId($storeId)
                ->addAttributeToSelect(['name', 'url_path', 'is_active'])
                ->addPathsFilter(['1/' . $rootId . '/'])
                ->addAttributeToFilter('is_active', (string) 1)
                ->addAttributeToFilter('level', ['gt' => 1])
                ->setOrder('path', 'ASC');

            // One grouped query for all product counts. Direct assignment counts only:
            // anchor roll-up counts cost one query per category.
            $collection->loadProductCount($collection->getItems(), true, false);

            $urlSuffix = (string) $this->scopeConfig->getValue(
                'catalog/seo/category_url_suffix',
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            // Children of a disabled subtree are individually still is_active=1, so
            // only emit categories whose full ancestor chain has been emitted.
            $visible = [$rootId => true];
            foreach ($collection as $category) {
                $parentId = (int) $category->getParentId();
                if (!isset($visible[$parentId])) {
                    continue;
                }
                $visible[(int) $category->getId()] = true;

                $level  = max(0, (int) $category->getLevel() - 2);
                $indent = str_repeat('  ', $level);
                $url    = $baseUrl . '/' . ltrim((string) $category->getUrlPath(), '/') . $urlSuffix;
                $count  = (int) $category->getProductCount();
                $suffix = $count > 0 ? " ({$count} products)" : '';
                $lines[] = "{$indent}- {$category->getName()}{$suffix}: {$url}";
            }
        } catch (\Exception) {
            $lines[] = '(category data unavailable)';
        }

        $lines[] = '';
        return implode("\n", $lines);
    }
}
