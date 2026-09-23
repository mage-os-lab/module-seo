<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Cms\Page;

use Magento\Cms\Model\Page\DataProvider;
use Magento\Framework\App\RequestInterface;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Observer\Adminhtml\SaveCmsPageSeoConfig;

/**
 * Loads the stored robots override into the CMS page form.
 *
 * The CMS page form has no modifier pool — unlike the category and product forms — so its data
 * provider is extended directly. The value lives in mageos_seo_cms_page_config rather than on
 * cms_page, so nothing else would put it in front of the merchant.
 */
class AddSeoConfigToFormData
{
    /**
     * @param ConfigRepository $configRepository
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Add the stored override to each page's form data.
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

        $storeId = (int) $this->request->getParam('store', 0);

        foreach ($result as $pageId => $data) {
            if (!\is_array($data)) {
                continue;
            }

            $config = $this->configRepository->getForPage((int) $pageId, $storeId);

            $result[$pageId][SaveCmsPageSeoConfig::FIELD_ROBOTS_META] = (string) ($config['robots_meta'] ?? '');
        }

        return $result;
    }
}
