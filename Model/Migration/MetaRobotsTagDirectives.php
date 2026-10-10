<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Migration;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page as CmsPageResource;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\ResourceModel\MetaRobotsTagFlags;

/**
 * The robots directive MageOS_MetaRobotsTag gave each flagged product, category and CMS page, on
 * each store view the entity is on.
 *
 * That module stores three booleans: store-scoped EAV attributes on products and categories, and
 * plain columns on cms_page. It applies them by flipping tokens in the store view's own default
 * robots. So the directive a visitor was served is the flags as that store view reads them (its
 * own value, or the default one it falls back to), resolved against that store view's default
 * (FlagsToDirective).
 *
 * Only the store views an entity is on are answered for: a product's websites, a category's root
 * category, a CMS page's store views. A flag elsewhere was never served.
 */
class MetaRobotsTagDirectives
{
    private const FLAGS = FlagsToDirective::FLAGS;

    private const PATH_DEFAULT_ROBOTS = 'design/search_engine_robots/default_robots';

    /**
     * Entities looked up per query.
     */
    private const CHUNK = 500;

    /**
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param CmsPageCollectionFactory $cmsPageCollectionFactory
     * @param ProductResource $productResource
     * @param CmsPageResource $cmsPageResource
     * @param EavConfig $eavConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param MetaRobotsTagFlags $cmsPageColumns
     * @param FlagsToDirective $flagsToDirective
     */
    public function __construct(
        private readonly ProductCollectionFactory  $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CmsPageCollectionFactory  $cmsPageCollectionFactory,
        private readonly ProductResource           $productResource,
        private readonly CmsPageResource           $cmsPageResource,
        private readonly EavConfig                 $eavConfig,
        private readonly ScopeConfigInterface      $scopeConfig,
        private readonly StoreManagerInterface     $storeManager,
        private readonly MetaRobotsTagFlags        $cmsPageColumns,
        private readonly FlagsToDirective          $flagsToDirective
    ) {
    }

    /**
     * Whether MageOS_MetaRobotsTag's attributes or columns are in the database.
     *
     * They outlive the module: a merchant moving away has often disabled or removed it already.
     *
     * @return bool
     */
    public function exist(): bool
    {
        return $this->hasEavFlags(Product::ENTITY)
            || $this->hasEavFlags(Category::ENTITY)
            || $this->cmsPageColumns->existOnCmsPage(self::FLAGS);
    }

    /**
     * Every product flagged on a store view it is on.
     *
     * By product ID, then store view ID: the directive, or null where the product has no flag. Every
     * store view the product is on is there.
     *
     * @return array<int, array<int, string|null>>
     */
    public function products(): array
    {
        if (!$this->hasEavFlags(Product::ENTITY)) {
            return [];
        }

        $storeViews = $this->storeViews();
        $directives = [];
        foreach ($storeViews as $storeId => $storeView) {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect(self::FLAGS);
            $collection->addAttributeToFilter($this->anyFlagSet(), null, 'left');

            foreach ($collection as $product) {
                $directives[(int) $product->getId()][$storeId]
                    = $this->flagsToDirective->convert($product->getData(), $storeView['default']);
            }
        }

        $onStoreViews = [];
        foreach (array_chunk(array_keys($directives), self::CHUNK) as $productIds) {
            foreach ($this->productResource->getWebsiteIdsByProductIds($productIds) as $productId => $websiteIds) {
                $websiteIds = array_map('intval', $websiteIds);
                $onStoreViews[(int) $productId] = array_keys(array_filter(
                    $storeViews,
                    static fn (array $storeView): bool => \in_array($storeView['website'], $websiteIds, true)
                ));
            }
        }

        return $this->onTheirStoreViews($directives, $onStoreViews);
    }

    /**
     * Every category flagged on a store view whose root category it is under.
     *
     * @return array<int, array<int, string|null>> As products() returns
     */
    public function categories(): array
    {
        if (!$this->hasEavFlags(Category::ENTITY)) {
            return [];
        }

        $storeViews   = $this->storeViews();
        $directives   = [];
        $onStoreViews = [];
        foreach ($storeViews as $storeId => $storeView) {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect(self::FLAGS);
            $collection->addAttributeToFilter($this->anyFlagSet(), null, 'left');

            foreach ($collection as $category) {
                $categoryId = (int) $category->getId();
                $directives[$categoryId][$storeId]
                    = $this->flagsToDirective->convert($category->getData(), $storeView['default']);
                $onStoreViews[$categoryId] ??= $this->storeViewsUnder(
                    (string) $category->getData('path'),
                    $storeViews
                );
            }
        }

        return $this->onTheirStoreViews($directives, $onStoreViews);
    }

    /**
     * Every flagged CMS page, on each store view it is assigned to.
     *
     * The flags are columns, the same on every store view; each store view's default robots still
     * applies.
     *
     * @return array<int, array<int, string|null>> As products() returns
     */
    public function cmsPages(): array
    {
        if (!$this->cmsPageColumns->existOnCmsPage(self::FLAGS)) {
            return [];
        }

        $collection = $this->cmsPageCollectionFactory->create();
        $collection->addFieldToFilter(self::FLAGS, array_fill(0, \count(self::FLAGS), ['eq' => 1]));

        $storeViews   = $this->storeViews();
        $directives   = [];
        $onStoreViews = [];
        foreach ($collection as $page) {
            $pageId                = (int) $page->getId();
            $storeIds              = array_map('intval', $this->cmsPageResource->lookupStoreIds($pageId));
            $onStoreViews[$pageId] = \in_array(0, $storeIds, true) ? array_keys($storeViews) : $storeIds;

            foreach ($storeViews as $storeId => $storeView) {
                $directives[$pageId][$storeId] = $this->flagsToDirective->convert(
                    $page->getData(),
                    $storeView['default']
                );
            }
        }

        return $this->onTheirStoreViews($directives, $onStoreViews);
    }

    /**
     * Each entity's directives on the store views it is on, null where it had no flag there.
     *
     * An entity on no store view is left out: its flags were never served.
     *
     * @param array<int,array<int,string|null>> $directives Entity ID => store view ID => directive
     * @param array<int,int[]> $onStoreViews Entity ID => the store views it is on
     * @return array<int, array<int, string|null>>
     */
    private function onTheirStoreViews(array $directives, array $onStoreViews): array
    {
        $served = [];
        foreach ($directives as $entityId => $byStoreView) {
            $storeIds = $onStoreViews[$entityId] ?? [];
            sort($storeIds);
            foreach ($storeIds as $storeId) {
                $served[$entityId][$storeId] = $byStoreView[$storeId] ?? null;
            }
        }

        return $served;
    }

    /**
     * The store views whose root category a category is under.
     *
     * @param string $path The category's path, "1/2/14"
     * @param array<int,array{default:string,website:int,root:int}> $storeViews
     * @return int[]
     */
    private function storeViewsUnder(string $path, array $storeViews): array
    {
        $ancestors = explode('/', $path);

        return array_keys(array_filter(
            $storeViews,
            static fn (array $storeView): bool => \count($ancestors) > 2
                && (int) $ancestors[1] === $storeView['root']
        ));
    }

    /**
     * Every store view, with what deciding its directives needs.
     *
     * @return array<int, array{default: string, website: int, root: int}>
     */
    private function storeViews(): array
    {
        $roots = [];
        foreach ($this->storeManager->getGroups() as $group) {
            $roots[(int) $group->getId()] = (int) $group->getRootCategoryId();
        }

        $storeViews = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId              = (int) $store->getId();
            $storeViews[$storeId] = [
                'default' => (string) $this->scopeConfig->getValue(
                    self::PATH_DEFAULT_ROBOTS,
                    ScopeInterface::SCOPE_STORE,
                    $storeId
                ),
                'website' => (int) $store->getWebsiteId(),
                'root'    => $roots[(int) $store->getStoreGroupId()] ?? 0,
            ];
        }
        ksort($storeViews);

        return $storeViews;
    }

    /**
     * Whether MageOS_MetaRobotsTag's attributes exist for an EAV entity type.
     *
     * @param string $entityType
     * @return bool
     */
    private function hasEavFlags(string $entityType): bool
    {
        foreach (self::FLAGS as $flag) {
            if (!$this->eavConfig->getAttribute($entityType, $flag)->getAttributeId()) {
                return false;
            }
        }

        return true;
    }

    /**
     * An OR filter matching an entity with any flag set.
     *
     * @return array<int, array<string, mixed>>
     */
    private function anyFlagSet(): array
    {
        return array_map(
            static fn (string $flag): array => ['attribute' => $flag, 'eq' => 1],
            self::FLAGS
        );
    }
}
