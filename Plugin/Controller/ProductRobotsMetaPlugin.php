<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Controller;

use Magento\Catalog\Controller\Product\View as ProductView;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Config;

class ProductRobotsMetaPlugin
{
    /**
     * @param Registry $registry
     * @param PageConfig $pageConfig
     * @param Config $seoConfig
     * @param ProductOverrideRepository $productOverrideRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Registry                  $registry,
        private readonly PageConfig                $pageConfig,
        private readonly Config                    $seoConfig,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly StoreManagerInterface     $storeManager
    ) {
    }

    /**
     * Apply robots meta after the product view controller executes.
     *
     * @param \Magento\Catalog\Controller\Product\View $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterExecute(ProductView $subject, mixed $result): mixed
    {
        $product = $this->registry->registry('current_product');
        if (!$product) {
            return $result;
        }

        $storeId    = (int) $this->storeManager->getStore()->getId();
        $productId  = (int) $product->getId();

        $productOverride = $this->productOverrideRepository->getForProduct($productId, $storeId);
        $robotsMeta      = $productOverride['robots_meta'] ?? null;

        if (empty($robotsMeta)) {
            $robotsMeta = $this->seoConfig->getRobotsProductDefault($storeId);
        }

        if (!empty($robotsMeta)) {
            $this->pageConfig->setRobots($robotsMeta);
        }

        return $result;
    }
}
