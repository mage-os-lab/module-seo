<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\RobotsMeta\Provider;

use MageOS\Seo\Api\RobotsMetaProviderInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\Config;

/**
 * Robots meta for CMS pages: the page's own override, else the configured CMS default.
 *
 * Product and category pages have had a per-entity override since this module's robots work
 * began; CMS pages had only a store-wide default, which is what MageOS_MetaRobotsTag's per-page
 * flags were there for.
 *
 * The chain is public (forPages()) so the sitemap asks the same question the page does, for a
 * chunk of pages at once. The home page is a CMS page here too: core adds cms_page_view to it.
 */
class CmsPageRobotsProvider implements RobotsMetaProviderInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     * @param Config $seoConfig
     * @param CmsPageConfigRepository $cmsPageConfigRepository
     */
    public function __construct(
        private readonly CmsPageResolver         $cmsPageResolver,
        private readonly Config                  $seoConfig,
        private readonly CmsPageConfigRepository $cmsPageConfigRepository
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['cms_page_view'];
    }

    /**
     * @inheritdoc
     */
    public function getRobots(int $storeId): ?string
    {
        $page = $this->cmsPageResolver->resolve();
        if ($page === null) {
            return null;
        }

        $config = $this->cmsPageConfigRepository->getForPage((int) $page->getId(), $storeId);

        return $this->directive($config['robots_meta'] ?? null, $storeId);
    }

    /**
     * This module's directive for each CMS page, in one read.
     *
     * @param int[] $pageIds
     * @param int $storeId
     * @return array<int,string|null> page ID => directive, null where this module has none
     */
    public function forPages(array $pageIds, int $storeId): array
    {
        return array_map(
            fn (array $config): ?string => $this->directive($config['robots_meta'] ?? null, $storeId),
            $this->cmsPageConfigRepository->getForPages($pageIds, $storeId)
        );
    }

    /**
     * The page's own directive, else the CMS Pages default; null when neither says anything.
     *
     * @param mixed $override
     * @param int $storeId
     * @return string|null
     */
    private function directive(mixed $override, int $storeId): ?string
    {
        $robotsMeta = empty($override) ? $this->seoConfig->getRobotsCmsDefault($storeId) : $override;

        return empty($robotsMeta) ? null : (string) $robotsMeta;
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return 100;
    }
}
