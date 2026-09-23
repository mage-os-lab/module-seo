<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Migration;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\ResourceModel\MetaRobotsTagFlags;

/**
 * Carries MageOS_MetaRobotsTag's per-entity flags into this module's overrides.
 *
 * That module stores three booleans — no_index, no_follow, no_archive — as EAV attributes on
 * products and categories and as columns on cms_page, and applies them by flipping tokens in
 * whatever robots directive the page already had. This module stores a whole directive instead,
 * so migrating means resolving each entity's flags against the store's configured default and
 * writing the result the visitor was actually being served.
 *
 * Only entities with at least one flag set are touched: a flagless entity was never overridden,
 * and writing a directive for it would turn "follow the store setting" into a fixed value.
 *
 * Safe to run when MageOS_MetaRobotsTag was never installed — the attributes and columns simply
 * are not there, and each source reports nothing to do. Safe to run twice: each write replaces
 * the row for that entity and scope rather than adding one.
 */
class MetaRobotsTagImporter
{
    /**
     * The flags, in the order they appear in a robots directive.
     */
    private const FLAGS = FlagsToDirective::FLAGS;

    /**
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param CmsPageCollectionFactory $cmsPageCollectionFactory
     * @param ProductOverrideRepository $productOverrideRepository
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param CmsPageConfigRepository $cmsPageConfigRepository
     * @param EavConfig $eavConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param MetaRobotsTagFlags $flags
     * @param FlagsToDirective $flagsToDirective
     */
    public function __construct(
        private readonly ProductCollectionFactory  $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CmsPageCollectionFactory  $cmsPageCollectionFactory,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly CategoryConfigRepository  $categoryConfigRepository,
        private readonly CmsPageConfigRepository   $cmsPageConfigRepository,
        private readonly EavConfig                 $eavConfig,
        private readonly ScopeConfigInterface      $scopeConfig,
        private readonly MetaRobotsTagFlags        $flags,
        private readonly FlagsToDirective          $flagsToDirective
    ) {
    }

    /**
     * Import every flagged product, category and CMS page.
     *
     * @return array<string, int> Rows written, keyed by entity type
     */
    public function import(): array
    {
        return [
            'product'  => $this->importProducts(),
            'category' => $this->importCategories(),
            'cms_page' => $this->importCmsPages(),
        ];
    }

    /**
     * Import every product carrying a flag, at global scope.
     *
     * @return int
     */
    private function importProducts(): int
    {
        if (!$this->hasEavFlags(\Magento\Catalog\Model\Product::ENTITY)) {
            return 0;
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(self::FLAGS);
        $collection->addAttributeToFilter($this->anyFlagSet(), null, 'left');

        $written = 0;
        foreach ($collection as $product) {
            $directive = $this->directiveFor($product->getData(), 0);
            if ($directive === null) {
                continue;
            }

            $this->productOverrideRepository->save((int) $product->getId(), 0, ['robots_meta' => $directive]);
            $written++;
        }

        return $written;
    }

    /**
     * Import every category carrying a flag, at global scope.
     *
     * @return int
     */
    private function importCategories(): int
    {
        if (!$this->hasEavFlags(\Magento\Catalog\Model\Category::ENTITY)) {
            return 0;
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(self::FLAGS);
        $collection->addAttributeToFilter($this->anyFlagSet(), null, 'left');

        $written = 0;
        foreach ($collection as $category) {
            $directive = $this->directiveFor($category->getData(), 0);
            if ($directive === null) {
                continue;
            }

            $this->categoryConfigRepository->save((int) $category->getId(), ['robots_meta' => $directive], 0);
            $written++;
        }

        return $written;
    }

    /**
     * Import every CMS page carrying a flag, at global scope.
     *
     * @return int
     */
    private function importCmsPages(): int
    {
        if (!$this->flags->existOnCmsPage(self::FLAGS)) {
            return 0;
        }

        $collection = $this->cmsPageCollectionFactory->create();
        $collection->addFieldToFilter(self::FLAGS, array_fill(0, \count(self::FLAGS), ['eq' => 1]));

        $written = 0;
        foreach ($collection as $page) {
            $directive = $this->directiveFor($page->getData(), 0);
            if ($directive === null) {
                continue;
            }

            $this->cmsPageConfigRepository->save((int) $page->getId(), ['robots_meta' => $directive], 0);
            $written++;
        }

        return $written;
    }

    /**
     * The directive a set of flags resolves to, or null when none of them is set.
     *
     * Built from the store's configured default, because that is what MageOS_MetaRobotsTag was
     * flipping tokens in: a page flagged only no_archive kept whatever index and follow the store
     * default gave it.
     *
     * @param mixed[] $data
     * @param int $storeId
     * @return string|null
     */
    private function directiveFor(array $data, int $storeId): ?string
    {
        $default = (string) $this->scopeConfig->getValue(
            'design/search_engine_robots/default_robots',
            ScopeInterface::SCOPE_STORE,
            $storeId ?: null
        );

        return $this->flagsToDirective->convert($data, $default);
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
