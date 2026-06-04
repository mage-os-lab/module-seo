<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsTxt;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
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
 */
class LlmsTxtBuilder
{
    /**
     * @param OrganisationRepositoryInterface $organisationRepository
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param SchemaBuilderPool $builderPool
     * @param \MageOS\Seo\Model\LlmsTxt\SectionProviderInterface[] $sectionProviders
     */
    public function __construct(
        private readonly OrganisationRepositoryInterface $organisationRepository,
        private readonly StoreManagerInterface           $storeManager,
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly CategoryCollectionFactory       $categoryCollectionFactory,
        private readonly SchemaBuilderPool               $builderPool,
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
        $org = $this->organisationRepository->get();
        /** @var \Magento\Store\Model\Store $store */
        $store   = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        $name    = $org->getName() ?: (string) $store->getName();

        $lines = [];

        // Header
        $lines[] = "# {$name}";
        if ($org->getDescription() !== '') {
            $lines[] = '> ' . $org->getDescription();
        }
        $lines[] = "> Base URL: {$baseUrl}";
        $lines[] = '> Locale: ' . $store->getLocaleCode();
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
        $adminEmail = (string) $this->scopeConfig->getValue(
            'trans_email/ident_support/email',
            ScopeInterface::SCOPE_STORE
        );
        if ($adminEmail !== '') {
            $lines[] = '## AI Contact';
            $lines[] = $adminEmail;
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
        $org = $this->organisationRepository->get();
        /** @var \Magento\Store\Model\Store $store */
        $store   = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        $name    = $org->getName() ?: (string) $store->getName();

        $lines = [];

        // Header
        $lines[] = "# {$name}";
        if ($org->getDescription() !== '') {
            $lines[] = '> ' . $org->getDescription();
        }
        $lines[] = "> Base URL: {$baseUrl}";
        $lines[] = '> Locale: ' . $store->getLocaleCode();

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
                    'Food'             => 'FoodProduct',
                    'Apparel'          => 'Apparel',
                    'Book'             => 'Book',
                    'Software'         => 'SoftwareApplication',
                    'ArtAndCraft'      => 'VisualArtwork',
                    'GenericProduct'   => 'Product',
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
        $adminEmail = (string) $this->scopeConfig->getValue(
            'trans_email/ident_support/email',
            ScopeInterface::SCOPE_STORE
        );
        if ($adminEmail !== '') {
            $lines[] = '## AI Contact';
            $lines[] = "Preferred contact for automated queries: {$adminEmail}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Build the category tree section.
     *
     * @param string $baseUrl
     * @return string
     */
    private function buildCategorySection(string $baseUrl): string
    {
        $lines = ['## Category Tree'];

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect(['name', 'url_key', 'url_path', 'level', 'is_active', 'product_count'])
                ->addAttributeToFilter('is_active', '1')
                ->addAttributeToFilter('level', ['gt' => 1])
                ->setOrder('path', 'ASC');

            foreach ($collection as $category) {
                $level  = max(0, (int) $category->getLevel() - 2);
                $indent = str_repeat('  ', $level);
                $url    = $baseUrl . '/' . ltrim((string) $category->getUrlPath(), '/');
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
