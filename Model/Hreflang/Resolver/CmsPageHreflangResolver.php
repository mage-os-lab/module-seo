<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang\Resolver;

use Magento\Framework\App\RequestInterface;
use MageOS\Seo\Api\HreflangResolverInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;

/**
 * Hreflang alternates for CMS pages, including the home page.
 *
 * The home page has no usable URL rewrite (it is served at the store root), so each store's base URL
 * is used directly. Other CMS pages resolve through their cms-page URL rewrites: those of every page
 * in the page's translation group when it has one, else the page's own in the other store views.
 */
class CmsPageHreflangResolver implements HreflangResolverInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     * @param RequestInterface $request
     * @param LinkBuilder $linkBuilder
     * @param ConfigRepository $cmsConfigRepository
     * @param UrlRewriteFetcher $urlRewriteFetcher
     */
    public function __construct(
        private readonly CmsPageResolver   $cmsPageResolver,
        private readonly RequestInterface  $request,
        private readonly LinkBuilder       $linkBuilder,
        private readonly ConfigRepository  $cmsConfigRepository,
        private readonly UrlRewriteFetcher $urlRewriteFetcher
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['cms_page_view', 'cms_index_index'];
    }

    /**
     * @inheritdoc
     */
    public function getLinks(): array
    {
        if ($this->isHomePage()) {
            return $this->linkBuilder->buildHome();
        }

        $page = $this->cmsPageResolver->resolve();
        if ($page === null) {
            return [];
        }

        $pageId = (int) $page->getId();
        $group  = $this->cmsConfigRepository->getHreflangGroup($pageId);
        if ($group === null) {
            return $this->linkBuilder->build('cms-page', $pageId);
        }

        return $this->linkBuilder->buildFromPaths($this->urlRewriteFetcher->fetchForCmsGroup($group));
    }

    /**
     * Whether the current request is the store home page.
     *
     * @return bool
     */
    private function isHomePage(): bool
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->request;
        return trim($request->getPathInfo(), '/') === '';
    }
}
