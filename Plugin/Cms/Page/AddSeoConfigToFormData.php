<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Cms\Page;

use Magento\Cms\Model\Page\DataProvider;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Observer\Adminhtml\SaveCmsPageSeoConfig;

/**
 * Loads the stored robots override and translation group into the CMS page form.
 *
 * The CMS page form has no modifier pool — unlike the category and product forms — so its data
 * provider is extended directly. The value lives in mageos_seo_cms_page_config rather than on
 * cms_page, so nothing else would put it in front of the merchant.
 *
 * It reads the page's global (store 0) row, the one Observer\Adminhtml\SaveCmsPageSeoConfig writes.
 * Every save posts these fields back as loaded, so reading any other row would turn an untouched
 * save into a reset.
 */
class AddSeoConfigToFormData
{
    /**
     * @param ConfigRepository $configRepository
     */
    public function __construct(
        private readonly ConfigRepository $configRepository
    ) {
    }

    /**
     * Add the stored values to each page's form data.
     *
     * @param DataProvider $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetData(DataProvider $subject, mixed $result): mixed
    {
        if (!\is_array($result) || $result === []) {
            return $result;
        }

        foreach ($result as $pageId => $data) {
            if (!\is_array($data)) {
                continue;
            }

            $config = $this->configRepository->getForPage((int) $pageId);

            $result[$pageId][SaveCmsPageSeoConfig::FIELD_ROBOTS_META]    = (string) ($config['robots_meta'] ?? '');
            $result[$pageId][SaveCmsPageSeoConfig::FIELD_HREFLANG_GROUP] = (string) ($config['hreflang_group'] ?? '');
        }

        return $result;
    }
}
