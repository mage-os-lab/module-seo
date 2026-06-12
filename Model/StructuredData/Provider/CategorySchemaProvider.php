<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\Config;

class CategorySchemaProvider implements StructuredDataProviderInterface
{
    /**
     * @param LayerResolver $layerResolver
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param RequestInterface $request
     * @param ConfigRepository $categoryConfigRepository
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly LayerResolver         $layerResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly RequestInterface      $request,
        private readonly ConfigRepository      $categoryConfigRepository,
        private readonly Config                $seoConfig,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_category_view'];
    }

    /**
     * @inheritdoc
     */
    public function getSchemas(): array
    {
        try {
            $layer    = $this->layerResolver->get();
            $category = $layer->getCurrentCategory();

            if (!$category || !$category->getId()) {
                return [];
            }

            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
            $catUrl  = (string) $category->getUrl();

            $schemas = [];

            // CollectionPage schema
            $collectionPage = [
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                '@id'      => $catUrl . '#collectionpage',
                'name'     => $category->getName(),
                'url'      => $catUrl,
            ];

            $description = (string) $category->getDescription();
            if ($description !== '') {
                $collectionPage['description'] = $this->cleanDescription($description);
            }

            $schemas[] = $collectionPage;

            // ItemList — check if enabled for this category
            if ($this->isItemListEnabled((int) $category->getId(), $storeId)) {
                $itemList = $this->buildItemList($category, $baseUrl, $storeId);
                if (!empty($itemList)) {
                    $schemas[] = $itemList;
                }
            }

            return $schemas;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Check if ItemList is enabled for this category (category config > global config).
     *
     * @param int $categoryId
     * @param int $storeId
     * @return bool
     */
    private function isItemListEnabled(int $categoryId, int $storeId = 0): bool
    {
        $config = $this->categoryConfigRepository->getForCategory($categoryId, [], $storeId);

        if (isset($config['item_list_enabled'])) {
            return (bool) $config['item_list_enabled'];
        }

        return $this->seoConfig->isCategoryItemListEnabled($storeId);
    }

    /**
     * Build the ItemList schema, respecting current page and page size.
     *
     * @param \Magento\Catalog\Api\Data\CategoryInterface $category
     * @param string $baseUrl
     * @param int $storeId
     * @return mixed[]
     */
    private function buildItemList($category, string $baseUrl, int $storeId = 0): array
    {
        $currentPage = max(1, (int) $this->request->getParam('p', 1));

        $configMax    = $this->seoConfig->getCategoryItemListMax($storeId);
        $storeDefault = (int) $this->scopeConfig->getValue(
            'catalog/frontend/grid_per_page',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $requestLimit = (int) $this->request->getParam('product_list_limit', 0);

        if ($requestLimit > 0) {
            $maxItems = min($requestLimit, $configMax);
        } elseif ($storeDefault > 0) {
            $maxItems = min($storeDefault, $configMax);
        } else {
            $maxItems = $configMax;
        }

        try {
            // Use the layer's existing product collection so that the sort order,
            // filters, and any other toolbar state match exactly what the page shows.
            // Clone it to avoid mutating the original, then apply our own page/limit.
            $collection = clone $this->layerResolver->get()->getProductCollection();
            $collection->addAttributeToSelect(['name', 'url_key', 'small_image', 'thumbnail'])
                ->setPageSize($maxItems)
                ->setCurPage($currentPage);

            $items        = [];
            $positionBase = ($currentPage - 1) * $maxItems;
            $position     = $positionBase + 1;

            foreach ($collection as $product) {
                $item = [
                    '@type'    => 'ListItem',
                    'position' => $position,
                    'url'      => $product->getProductUrl(),
                    'name'     => $product->getName(),
                ];

                $imageUrl = (string) $product->getData('small_image') ?: (string) $product->getData('thumbnail');
                if ($imageUrl !== '' && $imageUrl !== 'no_selection') {
                    $item['image'] = $baseUrl . '/media/catalog/product' . $imageUrl;
                }

                $items[] = $item;
                $position++;
            }

            if (empty($items)) {
                return [];
            }

            return [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'numberOfItems'   => \count($items),
                'itemListElement' => $items,
            ];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Strip PageBuilder inline styles, CSS blocks, and HTML tags from a category description.
     *
     * PageBuilder outputs inline &lt;style&gt; blocks and data-pb-style attributes
     * that survive strip_tags() — these must be removed first.
     *
     * @param string $html
     * @return string
     */
    private function cleanDescription(string $html): string
    {
        // Remove <style> blocks entirely (PageBuilder inline CSS)
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        // Remove all remaining HTML tags
        $clean = strip_tags($clean);

        // Collapse whitespace
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        return mb_substr($clean, 0, 500);
    }
}
