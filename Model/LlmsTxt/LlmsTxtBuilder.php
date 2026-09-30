<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsTxt;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Organization\ContactEmail;
use MageOS\Seo\Model\Product\SchemaBuilderPool;

/**
 * Builds the /llms.txt and /llms-full.txt document bodies.
 *
 * Output follows the llms.txt format (https://llmstxt.org), in this order:
 *
 *   1. an H1 with the site name (the only required part);
 *   2. a one-line blockquote summary, omitted when there is no description;
 *   3. details: paragraphs and lists, but no headings;
 *   4. H2 sections, each a "file list": list items of the form
 *      "- [name](url)" with optional ": notes".
 *
 * Only list items with markdown links may appear under an H2. Plain facts
 * (base URL, locale, schema types, contact) therefore belong to the details
 * block. Lighthouse's llms-txt audit also needs at least one markdown link:
 * a file without one scores worse than no file at all.
 *
 * Extended vendor and category data is injected via provider arrays registered
 * in di.xml — allowing SellersSeo (and any future bridge) to contribute content
 * without coupling this class to those modules. See SectionProviderInterface for
 * how provider output is placed.
 *
 * The locale is the store view's configured one (Config::getLocaleCode()); the line is left out
 * when there is none. The AI contact is Organization\ContactEmail's; the line is left out when
 * there is none.
 */
class LlmsTxtBuilder
{
    /**
     * Square brackets in a link label are swapped for round ones. Backslash escapes
     * (\[ \]) are valid CommonMark, but the reference llms_txt parser matches the
     * label as [^\]]+ and would not read the link.
     */
    private const LABEL_REPLACEMENTS = [
        '[' => '(',
        ']' => ')',
    ];

    /**
     * Characters that would end a markdown link destination early.
     */
    private const URL_REPLACEMENTS = [
        ' ' => '%20',
        '(' => '%28',
        ')' => '%29',
    ];

    /**
     * Book/Software/ArtAndCraft emit multi-type ["Product", X] nodes;
     * listed by their distinguishing secondary type.
     */
    private const TEMPLATE_TYPE_MAP = [
        'Book'        => 'Book',
        'Software'    => 'SoftwareApplication',
        'ArtAndCraft' => 'VisualArtwork',
    ];

    /**
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param SchemaBuilderPool $builderPool
     * @param Config $seoConfig
     * @param ContactEmail $contactEmail
     * @param \MageOS\Seo\Model\LlmsTxt\SectionProviderInterface[] $sectionProviders
     */
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizationRepository,
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
        return $this->build(false);
    }

    /**
     * Build the extended /llms-full.txt document.
     *
     * @return string
     */
    public function buildFull(): string
    {
        return $this->build(true);
    }

    /**
     * Assemble one document in llms.txt section order.
     *
     * @param bool $full
     * @return string
     */
    private function build(bool $full): string
    {
        /** @var \Magento\Store\Model\Store $store */
        $store     = $this->storeManager->getStore();
        $storeId   = (int) $store->getId();
        $websiteId = (int) $this->storeManager->getWebsite()->getId();
        $org       = $this->organizationRepository->getForScope($storeId, $websiteId);
        $baseUrl   = rtrim((string) $store->getBaseUrl(), '/');
        $name      = $this->oneLine($org->getName() ?: (string) $store->getName());

        // Provider output: prose goes to the details block, H2 sections after ours.
        $providerDetails  = [];
        $providerSections = [];
        foreach ($this->sectionProviders as $provider) {
            if (!$provider instanceof SectionProviderInterface) {
                continue;
            }
            $section = trim($full ? $provider->getFullSection() : $provider->getConciseSection());
            if ($section === '') {
                continue;
            }
            if (str_starts_with($section, '## ')) {
                $providerSections[] = $section;
            } else {
                $providerDetails[] = $section;
            }
        }

        $blocks = ["# {$name}"];

        // A summary must be one line: a newline would end the blockquote. With no
        // description there is no summary; an empty or metadata-only one misleads.
        $summary = $this->oneLine($org->getDescription());
        if ($summary !== '') {
            $blocks[] = "> {$summary}";
        }

        $blocks[] = implode("\n", $this->buildDetails($full, $store, $baseUrl, $org->getSocialProfiles()));
        foreach ($providerDetails as $details) {
            $blocks[] = $details;
        }

        $keyUrls = [
            '## Key URLs',
            '',
            "- [Home]({$baseUrl}/): Store front page",
            "- [Sitemap]({$baseUrl}/sitemap.xml): XML sitemap of indexable pages",
        ];
        $blocks[] = implode("\n", $keyUrls);

        if ($full) {
            $categories = $this->buildCategorySection($baseUrl);
            if ($categories !== '') {
                $blocks[] = $categories;
            }
        }

        foreach ($providerSections as $section) {
            $blocks[] = $section;
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Build the details list: plain facts about the site, placed before the first H2.
     *
     * @param bool $full
     * @param \Magento\Store\Model\Store $store
     * @param string $baseUrl
     * @param string[] $socialProfiles
     * @return string[]
     */
    private function buildDetails(bool $full, $store, string $baseUrl, array $socialProfiles): array
    {
        $lines = ["- Base URL: {$baseUrl}"];

        $locale = trim($this->seoConfig->getLocaleCode((int) $store->getId()));
        if ($locale !== '') {
            $lines[] = "- Locale: {$locale}";
        }

        $lines[] = "- Search URL template: `{$baseUrl}/catalogsearch/result?q={query}`";

        if ($full && $socialProfiles !== []) {
            $links = [];
            foreach ($socialProfiles as $profile) {
                $profile = trim((string) $profile);
                if ($profile !== '') {
                    $links[] = "<{$profile}>";
                }
            }
            if ($links !== []) {
                $lines[] = '- Social profiles: ' . implode(', ', $links);
            }
        }

        // The spec lets llms.txt point at the structured data a site uses. Name
        // schema.org types an agent can look for, not this module's template codes.
        // The list says what the store's pages can carry: templates are assigned per
        // category or product, so "registered" is not the same as "in use".
        $templates = $this->builderPool->getAvailableTemplates();
        if (!empty($templates)) {
            $productTypes = [];
            foreach (array_keys($templates) as $templateCode) {
                $productTypes[] = self::TEMPLATE_TYPE_MAP[$templateCode] ?? 'Product';
            }
            $productTypes = array_values(array_unique(array_merge(['Product'], $productTypes)));

            if ($full) {
                $types = array_merge(
                    ['Organization', 'WebSite', 'CollectionPage', 'BreadcrumbList', 'ItemList'],
                    $productTypes
                );
                $lines[] = '- Structured data: schema.org JSON-LD; types the pages can carry: '
                    . implode(', ', $types);

                $described = [];
                foreach ($templates as $code => $label) {
                    $described[] = "{$code} ({$this->oneLine((string) $label)})";
                }
                $lines[] = '- Product schema templates: ' . implode(', ', $described);
            } else {
                $lines[] = '- Structured data: schema.org JSON-LD on product pages ('
                    . implode(', ', $productTypes) . ')';
            }
        }

        $contactEmail = trim($this->contactEmail->get());
        if ($contactEmail !== '') {
            $lines[] = "- Contact for automated queries: <{$contactEmail}>";
        }

        return $lines;
    }

    /**
     * Build the category tree section for the current store's tree only.
     *
     * Returns '' when there are no categories or they cannot be read: an H2 may
     * hold only link items, so there is no place for an "unavailable" note.
     *
     * @param string $baseUrl
     * @return string
     */
    private function buildCategorySection(string $baseUrl): string
    {
        $items = [];

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
                $url    = strtr(
                    $baseUrl . '/' . ltrim((string) $category->getUrlPath(), '/') . $urlSuffix,
                    self::URL_REPLACEMENTS
                );
                $label  = $this->linkLabel((string) $category->getName());
                $count  = (int) $category->getProductCount();
                $note   = $count > 0 ? ": {$count} products" : '';
                $items[] = "{$indent}- [{$label}]({$url}){$note}";
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- section omitted
            return '';
        }

        if ($items === []) {
            return '';
        }

        return implode("\n", array_merge(['## Category Tree', ''], $items));
    }

    /**
     * Collapse all whitespace, newlines included, to single spaces.
     *
     * @param string $text
     * @return string
     */
    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Make text safe as a markdown link label.
     *
     * @param string $text
     * @return string
     */
    private function linkLabel(string $text): string
    {
        return strtr($this->oneLine($text), self::LABEL_REPLACEMENTS);
    }
}
