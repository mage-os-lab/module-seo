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

        $config     = $this->cmsPageConfigRepository->getForPage((int) $page->getId(), $storeId);
        $robotsMeta = $config['robots_meta'] ?? null;

        if (empty($robotsMeta)) {
            $robotsMeta = $this->seoConfig->getRobotsCmsDefault($storeId);
        }

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
