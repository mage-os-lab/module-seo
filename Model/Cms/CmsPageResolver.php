<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Api\PageRepositoryInterface;
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
     * @param HomePageLoader $homePageLoader
     */
    public function __construct(
        private readonly PageRepositoryInterface      $pageRepository,
        private readonly RequestInterface             $request,
        private readonly GetPageByIdentifierInterface $getPageByIdentifier,
        private readonly StoreManagerInterface        $storeManager,
        private readonly HomePageLoader               $homePageLoader,
    ) {
    }

    /**
     * Whether the current request is for the store view's home page: its path is empty.
     *
     * The test core's router itself makes (`Framework\App\Router\Base::parseRequest()` routes an
     * empty path to `web/default/front`), after `Store\App\Request\PathInfoProcessor` has trimmed
     * the store code. Not the `cms_index_index` handle: `/cms/index/index` runs that action at
     * another URL, and with `web/default/front` changed `/` doesn't run it at all. Not the page's
     * identifier either: `/home` is core's to route.
     *
     * @return bool
     */
    public function isHomePage(): bool
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->request;

        return trim((string) $request->getPathInfo(), '/') === '';
    }

    /**
     * The current CMS page's URL, or an empty string when the request is not for one.
     *
     * The store base URL on the home page, whichever page the store view names as home; the base
     * URL plus the page's identifier on every other CMS page.
     *
     * @return string
     */
    public function currentUrl(): string
    {
        $page = $this->resolve();
        if ($page === null) {
            return '';
        }

        $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/') . '/';

        return $this->isHomePage() ? $baseUrl : $baseUrl . ltrim((string) $page->getIdentifier(), '/');
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
     * The page the store's base URL serves, loaded as core's home page action loads it — for the
     * sitemap, which lists that URL without a request to resolve.
     *
     * @param int $storeId
     * @return \Magento\Cms\Api\Data\PageInterface|null
     */
    public function resolveHome(int $storeId): ?PageInterface
    {
        return $this->homePageLoader->load($storeId);
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

        $storeId = (int) $this->storeManager->getStore()->getId();
        if ($this->isHomePage()) {
            return $this->homePageLoader->load($storeId);
        }

        return $this->getPageByIdentifier->execute($this->resolveIdentifier(), $storeId);
    }

    /**
     * Resolve the CMS page identifier from the request: its path.
     *
     * @return string
     */
    private function resolveIdentifier(): string
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->request;

        return trim((string) $request->getPathInfo(), '/');
    }
}
