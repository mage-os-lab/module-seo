<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang\Resolver;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\HreflangResolverInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\SelfReference;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;

/**
 * Hreflang alternates for CMS pages, including the home page.
 *
 * The home page has no usable URL rewrite (it is served at the store root), so each store's base URL
 * is used directly; it is recognised as CmsPageResolver::isHomePage() says, by an empty path. Other
 * CMS pages resolve through their cms-page URL rewrites: those of every page in the page's
 * translation group when it has one, else the page's own in the other store views.
 *
 * A page in a group declares the group only when it is the group's page for the current store view.
 * Two translations assigned to one store view is a mistake the group settles by page ID; the page
 * that loses declares nothing, rather than naming the other page as its own-language version.
 */
class CmsPageHreflangResolver implements HreflangResolverInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     * @param LinkBuilder $linkBuilder
     * @param ConfigRepository $cmsConfigRepository
     * @param UrlRewriteFetcher $urlRewriteFetcher
     * @param StoreManagerInterface $storeManager
     * @param SelfReference $selfReference
     */
    public function __construct(
        private readonly CmsPageResolver       $cmsPageResolver,
        private readonly LinkBuilder           $linkBuilder,
        private readonly ConfigRepository      $cmsConfigRepository,
        private readonly UrlRewriteFetcher     $urlRewriteFetcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly SelfReference         $selfReference
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
        if ($this->cmsPageResolver->isHomePage()) {
            return $this->linkBuilder->buildHome();
        }

        $page = $this->cmsPageResolver->resolve();
        if ($page === null) {
            return [];
        }

        $pageId   = (int) $page->getId();
        $ownLinks = $this->linkBuilder->build('cms-page', $pageId);
        $group    = $this->cmsConfigRepository->getHreflangGroup($pageId);
        if ($group === null) {
            return $ownLinks;
        }

        $groupLinks = $this->linkBuilder->buildFromPaths($this->urlRewriteFetcher->fetchForCmsGroup($group));
        $storeId    = (int) $this->storeManager->getStore()->getId();

        return $this->selfReference->includes($groupLinks, $storeId, $this->urlIn($ownLinks, $storeId))
            ? $groupLinks
            : [];
    }

    /**
     * The page's own URL in the store view, or an empty string when it has none there.
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $links
     * @param int $storeId
     * @return string
     */
    private function urlIn(array $links, int $storeId): string
    {
        foreach ($links as $link) {
            if ($link['store_id'] === $storeId) {
                return $link['url'];
            }
        }

        return '';
    }
}
