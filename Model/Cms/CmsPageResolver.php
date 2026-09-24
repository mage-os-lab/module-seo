<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\StoreManagerInterface;

class CmsPageResolver implements ResetAfterRequestInterface
{
    /** @var \Magento\Cms\Api\Data\PageInterface|null */
    private ?PageInterface $resolved = null;

    /** @var bool */
    private bool $attempted = false;

    /**
     * @param PageRepositoryInterface $pageRepository
     * @param RequestInterface $request
     * @param GetPageByIdentifierInterface $getPageByIdentifier
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly PageRepositoryInterface      $pageRepository,
        private readonly RequestInterface             $request,
        private readonly GetPageByIdentifierInterface $getPageByIdentifier,
        private readonly StoreManagerInterface        $storeManager,
        private readonly ScopeConfigInterface         $scopeConfig,
    ) {
    }

    /**
     * Return the current CMS page, or null if not on a CMS page or page not found.
     *
     * Result is memoised — the lookup runs at most once per request.
     *
     * @return \Magento\Cms\Api\Data\PageInterface|null
     */
    public function resolve(): ?PageInterface
    {
        if ($this->attempted) {
            return $this->resolved;
        }

        $this->attempted = true;

        try {
            $this->resolved = $this->resolvePage();
        } catch (NoSuchEntityException) {
            $this->resolved = null;
        }

        return $this->resolved;
    }

    /**
     * The store view's home page, or null when its configured page does not resolve there.
     *
     * The page the store's base URL serves, found as the storefront finds it — for the sitemap,
     * which lists that URL without a request to resolve.
     *
     * @param int $storeId
     * @return \Magento\Cms\Api\Data\PageInterface|null
     */
    public function resolveHome(int $storeId): ?PageInterface
    {
        $identifier = $this->homeIdentifier($storeId);
        if ($identifier === '') {
            return null;
        }

        try {
            return $this->getPageByIdentifier->execute($identifier, $storeId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * Drop the memoised page between worker-mode requests.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->resolved  = null;
        $this->attempted = false;
    }

    /**
     * Resolve the current CMS page from the request, or null when not on one.
     *
     * @throws NoSuchEntityException
     * @return \Magento\Cms\Api\Data\PageInterface|null
     */
    private function resolvePage(): ?PageInterface
    {
        $pageId = (int) $this->request->getParam('page_id');
        if ($pageId > 0) {
            return $this->pageRepository->getById($pageId);
        }

        $identifier = $this->resolveIdentifier();
        if ($identifier === '') {
            return null;
        }

        return $this->getPageByIdentifier->execute(
            $identifier,
            (int) $this->storeManager->getStore()->getId()
        );
    }

    /**
     * Resolve the CMS page identifier from the request.
     *
     * The request path, or the configured home identifier when the path is empty
     * (its pipe-delimited layout suffix stripped).
     *
     * @return string
     */
    private function resolveIdentifier(): string
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request    = $this->request;
        $identifier = trim($request->getPathInfo(), '/');
        if ($identifier !== '') {
            return $identifier;
        }

        return $this->homeIdentifier();
    }

    /**
     * The configured home page identifier, its pipe-delimited layout suffix stripped.
     *
     * @param int|null $storeId The current store view when null
     * @return string
     */
    private function homeIdentifier(?int $storeId = null): string
    {
        $homeIdentifier = (string) $this->scopeConfig->getValue(
            \Magento\Cms\Helper\Page::XML_PATH_HOME_PAGE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // Config value can include a pipe-delimited layout suffix, e.g. "home|2columns-left".
        return explode('|', $homeIdentifier)[0];
    }
}
