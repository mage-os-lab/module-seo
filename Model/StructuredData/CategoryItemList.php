<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\PathResolver as CategoryPathResolver;
use MageOS\Seo\Model\Config;

/**
 * The ItemList node for a category page, built from the products the page is already showing.
 *
 * Deliberately reads the layer's product collection **after** the listing has rendered, rather
 * than paginating a copy of it. The collection the layer hands out is the one the product list
 * uses; in `<head>`, before the toolbar has touched it, it has not been loaded yet, so taking a
 * copy there and iterating it runs the whole catalogue query a second time — once for this node
 * and once for the page the visitor actually sees. By end of body it is loaded and paginated, and
 * reading it costs nothing.
 *
 * That is also what makes the node correct: the positions and the item set now come from the page
 * as rendered, including every layered-navigation filter and the visitor's own sort order and
 * page size, instead of being reconstructed from request parameters.
 */
class CategoryItemList
{
    /**
     * @param LayerResolver $layerResolver
     * @param StoreManagerInterface $storeManager
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param CategoryPathResolver $categoryPathResolver
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly LayerResolver            $layerResolver,
        private readonly StoreManagerInterface    $storeManager,
        private readonly CategoryConfigRepository $categoryConfigRepository,
        private readonly CategoryPathResolver     $categoryPathResolver,
        private readonly Config                   $seoConfig
    ) {
    }

    /**
     * Build the ItemList schema for the current category, or an empty array when there is none.
     *
     * @return mixed[]
     */
    public function build(): array
    {
        $category = $this->currentCategory();
        if ($category === null) {
            return [];
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->isEnabled($category, $storeId)) {
            return [];
        }

        $items = $this->items($storeId);
        if ($items === []) {
            return [];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'numberOfItems'   => \count($items),
            'itemListElement' => $items,
        ];
    }

    /**
     * The category being viewed, or null when this is not a category page.
     *
     * @return CategoryInterface|null
     */
    private function currentCategory(): ?CategoryInterface
    {
        $category = $this->layerResolver->get()->getCurrentCategory();

        return $category && $category->getId() ? $category : null;
    }

    /**
     * Whether this category emits an ItemList: its own setting, else the global one.
     *
     * @param CategoryInterface $category
     * @param int $storeId
     * @return bool
     */
    private function isEnabled(CategoryInterface $category, int $storeId): bool
    {
        $config = $this->categoryConfigRepository->getForCategory(
            (int) $category->getId(),
            $this->categoryPathResolver->forCategory($category),
            $storeId
        );

        if (isset($config['item_list_enabled'])) {
            return (bool) $config['item_list_enabled'];
        }

        return $this->seoConfig->isCategoryItemListEnabled($storeId);
    }

    /**
     * The ListItem entries for the products on this page.
     *
     * @param int $storeId
     * @return mixed[]
     */
    private function items(int $storeId): array
    {
        $collection = $this->layerResolver->get()->getProductCollection();

        // The configured maximum caps what is published; it does not re-paginate the page. A page
        // size larger than the maximum lists the first N of what is shown, never a different set.
        $maximum  = $this->seoConfig->getCategoryItemListMax($storeId);
        $pageSize = (int) $collection->getPageSize();
        $position = $pageSize > 0 ? (((int) $collection->getCurPage() - 1) * $pageSize) + 1 : 1;

        $items = [];

        /** @var Product $product */
        foreach ($collection as $product) {
            if (\count($items) >= $maximum) {
                break;
            }

            $item = [
                '@type'    => 'ListItem',
                'position' => $position,
                'url'      => $product->getProductUrl(),
                'name'     => $product->getName(),
            ];

            $image = $this->imageUrl($product);
            if ($image !== '') {
                $item['image'] = $image;
            }

            $items[] = $item;
            $position++;
        }

        return $items;
    }

    /**
     * The product's list image as an absolute URL, or an empty string when it has none.
     *
     * @param Product $product
     * @return string
     */
    private function imageUrl(Product $product): string
    {
        $path = (string) $product->getData('small_image') ?: (string) $product->getData('thumbnail');
        if ($path === '' || $path === 'no_selection') {
            return '';
        }

        $mediaUrl = (string) $this->storeManager->getStore()
            ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        return rtrim($mediaUrl, '/') . '/catalog/product' . $path;
    }
}
