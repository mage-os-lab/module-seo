<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Controller;

use Magento\Catalog\Controller\Category\View as CategoryView;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;

class CategoryRobotsMetaPlugin
{
    /**
     * @param LayerResolver $layerResolver
     * @param PageConfig $pageConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param CategoryConfigRepository $categoryConfigRepository
     */
    public function __construct(
        private readonly LayerResolver            $layerResolver,
        private readonly PageConfig               $pageConfig,
        private readonly ScopeConfigInterface     $scopeConfig,
        private readonly CategoryConfigRepository $categoryConfigRepository
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
            $config     = $this->categoryConfigRepository->getForCategory($categoryId);
            $robotsMeta = $config['robots_meta'] ?? null;

            if (empty($robotsMeta)) {
                $robotsMeta = (string) $this->scopeConfig->getValue(
                    'mageos_seo_general/robots_meta/category_default',
                    ScopeInterface::SCOPE_STORE
                );
            }

            if (!empty($robotsMeta)) {
                $this->pageConfig->setRobots($robotsMeta);
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        }

        return $result;
    }
}
