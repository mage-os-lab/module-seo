<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Catalog\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Category\ProductOverrideRepository;

class SaveSeoOverridesPlugin
{
    /**
     * @param RequestInterface $request
     * @param ProductOverrideRepository $productOverrideRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RequestInterface          $request,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly StoreManagerInterface     $storeManager
    ) {
    }

    /**
     * After the product is saved, persist any SEO overrides from the Advanced SEO tab.
     *
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $subject
     * @param \Magento\Catalog\Api\Data\ProductInterface $result
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    public function afterSave(
        ProductRepositoryInterface $subject,
        ProductInterface           $result
    ): ProductInterface {
        $productId = (int) $result->getId();
        if ($productId <= 0) {
            return $result;
        }

        /** @var \Magento\Framework\App\Request\Http $postRequest */
        $postRequest = $this->request;
        $postData = $postRequest->getPostValue();

        if (!isset($postData['rs_seo_override_fields']) && !isset($postData['rs_seo_robots_meta'])) {
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $data    = [];

        if (isset($postData['rs_seo_override_fields'])) {
            $raw = (string) $postData['rs_seo_override_fields'];
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                $data['override_fields'] = \is_array($decoded) ? $decoded : [];
            } else {
                $data['override_fields'] = [];
            }
        }

        if (isset($postData['rs_seo_robots_meta'])) {
            $data['robots_meta'] = (string) $postData['rs_seo_robots_meta'] ?: null;
        }

        if (!empty($data)) {
            $this->productOverrideRepository->save($productId, $storeId, $data);
        }

        return $result;
    }
}
