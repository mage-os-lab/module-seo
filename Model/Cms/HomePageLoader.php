<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Helper\Page as PageHelper;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Loads a store view's home page exactly as core's home page action does.
 *
 * `Cms\Controller\Index\Index` hands `web/default/cms_home_page` to
 * `Cms\Helper\Page::prepareResultPage()`, which strips a `|…` suffix, sets the store and calls
 * `$page->load($value)`. `Cms\Model\ResourceModel\Page` then loads a numeric value by page ID and
 * anything else by identifier, and with the store set keeps active pages in that store view or all
 * store views, the store view's own first. The same call here means the page described is always
 * the page core renders — including a home page set by page ID, e.g. through `config:set`.
 */
class HomePageLoader
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param PageFactory $pageFactory
     * @param PageResource $pageResource
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageFactory          $pageFactory,
        private readonly PageResource         $pageResource
    ) {
    }

    /**
     * The store view's configured home page, or null when none is configured or it doesn't load.
     *
     * @param int $storeId
     * @return PageInterface|null
     */
    public function load(int $storeId): ?PageInterface
    {
        $value = (string) $this->scopeConfig->getValue(
            PageHelper::XML_PATH_HOME_PAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === '') {
            return null;
        }

        // Core's own stripping, condition included: a pipe at position 0 is left in place.
        $delimiterPosition = strrpos($value, '|');
        if ($delimiterPosition) {
            $value = substr($value, 0, $delimiterPosition);
        }

        $page = $this->pageFactory->create();
        $page->setStoreId($storeId);
        $this->pageResource->load($page, $value);

        return $page->getId() ? $page : null;
    }
}
