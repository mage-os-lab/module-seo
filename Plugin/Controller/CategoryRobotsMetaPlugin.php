<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Controller;

use Magento\Catalog\Controller\Category\View as CategoryView;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Config;

class CategoryRobotsMetaPlugin
{
    /**
     * @param LayerResolver $layerResolver
     * @param PageConfig $pageConfig
     * @param Config $seoConfig
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly LayerResolver            $layerResolver,
        private readonly PageConfig               $pageConfig,
        private readonly Config                   $seoConfig,
        private readonly CategoryConfigRepository $categoryConfigRepository,
        private readonly StoreManagerInterface    $storeManager
    ) {
    }

    /**
     * Apply robots meta after the category view controller executes.
     *
     * @param \Magento\Catalog\Controller\Category\View $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterExecute(CategoryView $subject, mixed $result): mixed
    {
        try {
            $category = $this->layerResolver->get()->getCurrentCategory();
            if (!$category) {
                return $result;
            }

            $categoryId = (int) $category->getId();
            $storeId    = (int) $this->storeManager->getStore()->getId();
            $config     = $this->categoryConfigRepository->getForCategory($categoryId, [], $storeId);
            $robotsMeta = $config['robots_meta'] ?? null;

            if (empty($robotsMeta)) {
                $robotsMeta = $this->seoConfig->getRobotsCategoryDefault($storeId);
            }

            if (!empty($robotsMeta)) {
                $this->pageConfig->setRobots($robotsMeta);
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        }

        return $result;
    }
}
