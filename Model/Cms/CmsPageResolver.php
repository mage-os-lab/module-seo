<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class CmsPageResolver
{
    /** @var \Magento\Cms\Api\Data\PageInterface|null */
    private ?PageInterface $resolved = null;

    /** @var bool */
    private bool $attempted = false;

    /**
     * @param PageRepositoryInterface $pageRepository
     * @param RequestInterface $request
     * @param PageFactory $pageFactory
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly RequestInterface        $request,
        private readonly PageFactory             $pageFactory,
        private readonly StoreManagerInterface   $storeManager,
        private readonly ScopeConfigInterface    $scopeConfig,
    ) {
    }

    /**
     * Return the current CMS page, or null if not on a CMS page or page not found.
     *
     * Result is memoised — the repository is called at most once per request.
     *
     * @return \Magento\Cms\Api\Data\PageInterface|null
     */
    public function resolve(): ?PageInterface
    {
        if ($this->attempted) {
            return $this->resolved;
        }

        $this->attempted = true;

        $pageId = (int) $this->resolveId();
        if (!$pageId) {
            return null;
        }

        try {
            $this->resolved = $this->pageRepository->getById($pageId);
        } catch (NoSuchEntityException) {
            $this->resolved = null;
        }

        return $this->resolved;
    }

    /**
     * Resolve the CMS page ID from the request parameters or path.
     *
     * @return int|null
     */
    private function resolveId(): int|null
    {
        $pageId = (int) $this->request->getParam('page_id');
        if ($pageId > 0) {
            return $pageId;
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request    = $this->request;
        $identifier = trim($request->getPathInfo(), '/');
        if ($identifier === '') {
            $homeIdentifier = (string) $this->scopeConfig->getValue(
                \Magento\Cms\Helper\Page::XML_PATH_HOME_PAGE,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            // Config value can include a pipe-delimited layout suffix e.g. "home|2columns-left"
            $homeIdentifier = explode('|', $homeIdentifier)[0];
            /** @var \Magento\Cms\Model\Page $page */
            $page = $this->pageFactory->create();
            return (int) $page->checkIdentifier($homeIdentifier, $this->storeManager->getStore()->getId()) ?: null;
        }

        $identifier = trim($request->getPathInfo(), '/');
        /** @var \Magento\Cms\Model\Page $page */
        $page   = $this->pageFactory->create();
        $pageId = (int) $page->checkIdentifier($identifier, $this->storeManager->getStore()->getId());
        if (!$pageId) {
            return null;
        }
        return (int) $pageId;
    }
}
