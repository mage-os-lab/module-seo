<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Catalog\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Category\ConfigRepository;

/**
 * Persists the SEO tab data from the category edit form after the category is saved.
 *
 * Plugged into CategoryRepositoryInterface::save (afterSave) so it fires regardless
 * of which admin controller triggers the save.
 */
class SaveSeoConfigPlugin
{
    /**
     * @param RequestInterface $request
     * @param ConfigRepository $configRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RequestInterface  $request,
        private readonly ConfigRepository  $configRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * After the category is saved, persist any SEO config submitted via the SEO tab.
     *
     * @param \Magento\Catalog\Api\CategoryRepositoryInterface $subject
     * @param \Magento\Catalog\Api\Data\CategoryInterface $result
     * @return \Magento\Catalog\Api\Data\CategoryInterface
     */
    public function afterSave(
        CategoryRepositoryInterface $subject,
        CategoryInterface           $result
    ): CategoryInterface {
        $categoryId = (int) $result->getId();
        if ($categoryId <= 0) {
            return $result;
        }

        /** @var \Magento\Framework\App\Request\Http $postRequest */
        $postRequest = $this->request;
        $postData = $postRequest->getPostValue();

        // Only proceed if the SEO tab fields were part of the submitted data
        if (!isset($postData['rs_seo_schema_template']) &&
            !isset($postData['rs_seo_enabled_fields']) &&
            !isset($postData['rs_seo_robots_meta']) &&
            !isset($postData['rs_seo_override_fields']) &&
            !isset($postData['rs_seo_item_list_enabled'])) {
            return $result;
        }

        $data = [];

        if (isset($postData['rs_seo_schema_template'])) {
            $data['schema_template'] = (string) $postData['rs_seo_schema_template'];
        }

        if (isset($postData['rs_seo_enabled_fields'])) {
            $fields = $postData['rs_seo_enabled_fields'];
            $data['enabled_fields'] = \is_array($fields) ? $fields : [];
        }

        if (isset($postData['rs_seo_item_list_enabled'])) {
            $val = $postData['rs_seo_item_list_enabled'];
            $data['item_list_enabled'] = ($val === '') ? null : (int) $val;
        }

        if (isset($postData['rs_seo_robots_meta'])) {
            $data['robots_meta'] = (string) $postData['rs_seo_robots_meta'] ?: null;
        }

        if (isset($postData['rs_seo_override_fields'])) {
            $raw = (string) $postData['rs_seo_override_fields'];
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                $data['override_fields'] = \is_array($decoded) ? $decoded : [];
            } else {
                $data['override_fields'] = [];
            }
        }

        if (!empty($data)) {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $this->configRepository->save($categoryId, $data, $storeId);
        }

        return $result;
    }
}
